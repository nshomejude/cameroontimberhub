<?php

namespace App\Services\Referrals;

use App\Enums\ReferralEarningStatus;
use App\Enums\ReferralPayoutStatus;
use App\Models\ReferralEarning;
use App\Models\ReferralPayout;
use App\Models\ReferralPayoutProfile;
use App\Models\User;
use App\Notifications\ReferralPayoutUpdatedNotification;
use App\Services\Payments\PayPalGateway;
use App\Services\TwoFactorStepUp;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Referral commission payouts — the only code that moves a ReferralEarning
 * to `paid` (owner decision: commissions are payable via PayPal).
 *
 * PayPal flow (two-person control, same shape as
 * App\Actions\Payments\ApprovePaymentCredentialChange):
 *  1. `requestPayPalPayout()` — admin A (payments.manage) asks to pay an
 *     APPROVED, unpaid earning to the referrer's PayPal email (snapshotted).
 *  2. `approvePayPalPayout()` — a DIFFERENT admin B approves (with a recent
 *     2FA step-up when staff 2FA is enforced). Only then is PayPal called:
 *     POST /v1/payments/payouts with sender_batch_id `CTH-REFPAY-{payout id}`
 *     (deterministic, so any retry is rejected/deduplicated by PayPal) and
 *     sender_item_id = earning id.
 *  3. Outcome arrives by webhook (`handlePayPalWebhook()`, signature already
 *     verified by PayPalGateway) or by `refresh()` (GET the batch; also run by
 *     `referrals:refresh-payouts`). SUCCESS → earning paid; FAILED / RETURNED /
 *     BLOCKED / … → attempt failed, earning payable again; UNCLAIMED → held
 *     until the receiver claims (or PayPal returns it).
 *
 * `markPaidManually()` records a MoMo / bank payment with a reference note.
 *
 * Never pay twice: a partial unique index allows one live (requested /
 * processing / unclaimed / succeeded) attempt per earning, every transition
 * is a locked conditional update, and PayPal deduplicates on sender_batch_id.
 */
class ReferralPayoutService
{
    /** PayPal item transaction_status → our attempt status. */
    private const PROVIDER_STATUS_MAP = [
        'SUCCESS' => ReferralPayoutStatus::Succeeded,
        'UNCLAIMED' => ReferralPayoutStatus::Unclaimed,
        'FAILED' => ReferralPayoutStatus::Failed,
        'RETURNED' => ReferralPayoutStatus::Failed,
        'BLOCKED' => ReferralPayoutStatus::Failed,
        'DENIED' => ReferralPayoutStatus::Failed,
        'REFUNDED' => ReferralPayoutStatus::Failed,
        'REVERSED' => ReferralPayoutStatus::Failed,
        'CANCELED' => ReferralPayoutStatus::Failed,
        'PENDING' => ReferralPayoutStatus::Processing,
        'ONHOLD' => ReferralPayoutStatus::Processing,
        'NEW' => ReferralPayoutStatus::Processing,
    ];

    /** Webhook event → provider status, for item events. */
    private const EVENT_STATUS_MAP = [
        'PAYMENT.PAYOUTS-ITEM.SUCCEEDED' => 'SUCCESS',
        'PAYMENT.PAYOUTS-ITEM.UNCLAIMED' => 'UNCLAIMED',
        'PAYMENT.PAYOUTS-ITEM.FAILED' => 'FAILED',
        'PAYMENT.PAYOUTS-ITEM.RETURNED' => 'RETURNED',
        'PAYMENT.PAYOUTS-ITEM.BLOCKED' => 'BLOCKED',
        'PAYMENT.PAYOUTS-ITEM.DENIED' => 'DENIED',
        'PAYMENT.PAYOUTS-ITEM.REFUNDED' => 'REFUNDED',
        'PAYMENT.PAYOUTS-ITEM.CANCELED' => 'CANCELED',
        'PAYMENT.PAYOUTS-ITEM.HELD' => 'ONHOLD',
    ];

    /** Money that came back AFTER a success — the earning is unpaid again. */
    private const REVERSAL_STATUSES = ['RETURNED', 'REFUNDED', 'REVERSED'];

    public function __construct(
        private readonly PayPalGateway $paypal,
        private readonly TwoFactorStepUp $stepUp,
    ) {}

    public function paypalConfigured(): bool
    {
        return $this->paypal->isConfigured();
    }

    /** @return list<string> */
    public static function paypalCurrencies(): array
    {
        return array_map('strtoupper', (array) config('payments.paypal_payouts.currencies', ['USD', 'EUR', 'GBP']));
    }

    // ------------------------------------------------------------------
    // Referrer payout destination
    // ------------------------------------------------------------------

    /**
     * Validation for the referrer's PayPal payout email — shared by
     * PATCH /api/v1/referrals/payout-settings, /account/settings and the
     * exporter-panel Referrals page so the surfaces cannot drift.
     */
    public const PAYPAL_EMAIL_RULES = ['nullable', 'string', 'max:254', 'email:rfc'];

    /** Validation for the free-text MoMo number / bank details (manual payouts). */
    public const MANUAL_DETAILS_RULES = ['nullable', 'string', 'max:500'];

    public function setPaypalEmail(User $user, ?string $email): ReferralPayoutProfile
    {
        $email = filled($email) ? strtolower(trim((string) $email)) : null;

        $profile = ReferralPayoutProfile::firstOrNew(['user_id' => $user->getKey()]);
        $before = $profile->exists ? $profile->maskedPaypalEmail() : null;
        $profile->paypal_email = $email;
        $profile->save();

        activity('referral_payout')
            ->performedOn($profile)
            ->causedBy($user)
            ->event('payout_email_updated')
            ->withProperties(['from' => $before, 'to' => $profile->maskedPaypalEmail()])
            ->log('Referral payout PayPal email updated');

        return $profile;
    }

    /**
     * Preferred MoMo number / bank details for commissions finance pays by
     * hand (XAF, or whenever PayPal is not available). `null` / "" removes
     * them. Only masked values reach the audit log.
     */
    public function setManualPayoutDetails(User $user, ?string $details): ReferralPayoutProfile
    {
        $details = filled($details) ? trim((string) $details) : null;

        $profile = ReferralPayoutProfile::firstOrNew(['user_id' => $user->getKey()]);
        $before = $profile->exists ? $profile->maskedManualPayoutDetails() : null;
        $profile->manual_payout_details = $details;
        $profile->save();

        activity('referral_payout')
            ->performedOn($profile)
            ->causedBy($user)
            ->event('manual_payout_details_updated')
            ->withProperties(['from' => $before, 'to' => $profile->maskedManualPayoutDetails()])
            ->log('Referral manual payout details updated');

        return $profile;
    }

    // ------------------------------------------------------------------
    // Eligibility
    // ------------------------------------------------------------------

    /** Why this earning cannot be sent through PayPal right now (null = it can). */
    public function paypalBlocker(ReferralEarning $earning): ?string
    {
        if (! $this->paypalConfigured()) {
            return 'PayPal is not configured (Payment settings → PayPal). Configure PayPal with Payouts enabled, or mark the commission paid manually.';
        }

        if ($earning->status !== ReferralEarningStatus::Approved) {
            return 'Only approved, unpaid commissions can be paid out (this one is '.strtolower($earning->status->label()).').';
        }

        if ($this->hasLivePayout($earning)) {
            return 'This commission already has a payout in progress or completed.';
        }

        if (! in_array(strtoupper((string) $earning->currency), self::paypalCurrencies(), true)) {
            return 'PayPal cannot pay '.strtoupper((string) $earning->currency).' — pay this commission manually (MoMo / bank) and mark it paid.';
        }

        if (ReferralPayoutProfile::paypalEmailFor($earning->referrer) === null) {
            return 'The referrer has not set a PayPal payout email yet.';
        }

        return null;
    }

    public function hasLivePayout(ReferralEarning $earning): bool
    {
        return ReferralPayout::where('referral_earning_id', $earning->getKey())
            ->whereIn('status', array_map(fn ($s) => $s->value, ReferralPayoutStatus::live()))
            ->exists();
    }

    // ------------------------------------------------------------------
    // Step 1 — request (admin A)
    // ------------------------------------------------------------------

    public function requestPayPalPayout(ReferralEarning $earning, User $requestedBy): ReferralPayout
    {
        self::authorize($requestedBy);

        $payout = DB::transaction(function () use ($earning, $requestedBy): ReferralPayout {
            $earning = ReferralEarning::whereKey($earning->getKey())->lockForUpdate()->firstOrFail();

            if (($reason = $this->paypalBlocker($earning)) !== null) {
                throw ValidationException::withMessages(['payout' => $reason]);
            }

            try {
                $payout = ReferralPayout::create([
                    'referral_earning_id' => $earning->getKey(),
                    'method' => ReferralPayout::METHOD_PAYPAL,
                    'status' => ReferralPayoutStatus::Requested,
                    'amount' => $earning->amount,
                    'currency' => strtoupper((string) $earning->currency),
                    'receiver_email' => ReferralPayoutProfile::paypalEmailFor($earning->referrer),
                    'requested_by' => $requestedBy->getKey(),
                    'requested_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['payout' => 'This commission already has a payout in progress or completed.']);
            }

            // Deterministic + unique per attempt: PayPal refuses a second
            // batch with the same id, so a retried submit can never pay twice.
            $payout->update(['sender_batch_id' => 'CTH-REFPAY-'.$payout->getKey()]);

            return $payout;
        });

        $this->log($payout, $requestedBy, 'requested', 'PayPal payout requested');

        return $payout;
    }

    // ------------------------------------------------------------------
    // Step 2 — approve (admin B ≠ A) → PayPal
    // ------------------------------------------------------------------

    public function approvePayPalPayout(ReferralPayout $payout, User $approvedBy, ?Request $httpRequest = null): ReferralPayout
    {
        self::authorize($approvedBy);

        if (! $payout->isPaypal() || $payout->status !== ReferralPayoutStatus::Requested) {
            throw ValidationException::withMessages(['payout' => 'This payout request has already been decided.']);
        }

        if ((int) $payout->requested_by === (int) $approvedBy->getKey()) {
            throw ValidationException::withMessages(['approved_by' => 'A payout must be approved by a different administrator than the one who requested it.']);
        }

        if (config('auth.require_staff_2fa')
            && (! $approvedBy->hasTwoFactorEnabled() || $httpRequest === null || ! $this->stepUp->isRecentlyVerified($httpRequest))) {
            throw ValidationException::withMessages(['two_factor' => 'Please re-confirm your two-factor code before approving a payout.']);
        }

        if (! $this->paypalConfigured()) {
            throw ValidationException::withMessages(['payout' => 'PayPal is not configured — the payout cannot be sent.']);
        }

        $earning = $payout->earning;
        if ($earning->status !== ReferralEarningStatus::Approved) {
            throw ValidationException::withMessages(['payout' => 'The commission is no longer approved and unpaid.']);
        }

        // The approver approved THIS destination; if the referrer changed it
        // since, a fresh request (showing the new email) is required.
        if (ReferralPayoutProfile::paypalEmailFor($earning->referrer) !== $payout->receiver_email) {
            throw ValidationException::withMessages(['payout' => 'The referrer changed their PayPal email after this request — reject it and request a new payout.']);
        }

        // Atomic requested → processing: of two concurrent approvals only one wins.
        $won = ReferralPayout::whereKey($payout->getKey())
            ->where('status', ReferralPayoutStatus::Requested->value)
            ->update([
                'status' => ReferralPayoutStatus::Processing->value,
                'decided_by' => $approvedBy->getKey(),
                'decided_at' => now(),
                'updated_at' => now(),
            ]);

        if ($won === 0) {
            throw ValidationException::withMessages(['payout' => 'This payout request has already been decided.']);
        }

        $payout->refresh();
        $this->log($payout, $approvedBy, 'approved', 'PayPal payout approved — submitting to PayPal');

        return $this->submit($payout);
    }

    public function rejectPayPalPayout(ReferralPayout $payout, User $rejectedBy, ?string $reason = null): void
    {
        self::authorize($rejectedBy);

        $updated = ReferralPayout::whereKey($payout->getKey())
            ->where('status', ReferralPayoutStatus::Requested->value)
            ->update([
                'status' => ReferralPayoutStatus::Rejected->value,
                'decided_by' => $rejectedBy->getKey(),
                'decided_at' => now(),
                'failure_reason' => $reason,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw ValidationException::withMessages(['payout' => 'This payout request has already been decided.']);
        }

        $this->log($payout->refresh(), $rejectedBy, 'rejected', 'PayPal payout request rejected', ['reason' => $reason]);
    }

    /**
     * POST the payout. A definite 4xx means PayPal created nothing → failed
     * (payable again). A timeout / 5xx / 429 is "unknown": the attempt stays
     * `processing` without a batch id and `refresh()` re-submits the SAME
     * sender_batch_id, which PayPal deduplicates.
     */
    private function submit(ReferralPayout $payout): ReferralPayout
    {
        $earning = $payout->earning;

        $body = [
            'sender_batch_header' => [
                'sender_batch_id' => $payout->sender_batch_id,
                'email_subject' => 'You have a referral commission from Cameroon Timber Hub',
                'email_message' => 'Thank you for referring a company to Cameroon Timber Hub. Your commission is on its way.',
            ],
            'items' => [[
                'recipient_type' => 'EMAIL',
                'amount' => [
                    'value' => number_format((float) $payout->amount, 2, '.', ''),
                    'currency' => $payout->currency,
                ],
                'receiver' => $payout->receiver_email,
                'note' => 'Referral commission '.$earning->source_reference,
                'sender_item_id' => (string) $earning->getKey(),
            ]],
        ];

        try {
            $response = $this->paypal->createPayout($body, (string) $payout->sender_batch_id);
        } catch (Throwable $e) {
            Log::error('PayPal payout submission failed — outcome unknown, will retry with the same sender_batch_id', [
                'referral_payout_id' => $payout->getKey(), 'error' => $e->getMessage(),
            ]);
            $payout->update(['failure_reason' => 'Submission outcome unknown ('.$e->getMessage().'). Use "Refresh status" to retry safely.', 'last_checked_at' => now()]);

            return $payout;
        }

        $payout->update(['submitted_at' => $payout->submitted_at ?? now(), 'last_checked_at' => now()]);

        if ($response->successful()) {
            $header = (array) $response->json('batch_header', []);
            $payout->update([
                'payout_batch_id' => $header['payout_batch_id'] ?? null,
                'provider_status' => $header['batch_status'] ?? null,
                'failure_reason' => null,
                'provider_payload' => $response->json(),
            ]);
            $this->log($payout, null, 'submitted', 'PayPal payout submitted', ['payout_batch_id' => $payout->payout_batch_id]);

            if (strtoupper((string) ($header['batch_status'] ?? '')) === 'DENIED') {
                $this->transition($payout, ReferralPayoutStatus::Failed, 'DENIED', [], 'PayPal denied the payout batch.');
            }

            return $payout->refresh();
        }

        $error = self::errorMessage($response);

        if (str_contains(strtoupper($response->body()), 'DUPLICATE')) {
            // Already submitted earlier (lost response) — never re-pay; needs
            // reconciliation against the PayPal dashboard.
            $payout->update(['provider_status' => 'DUPLICATE', 'failure_reason' => 'PayPal already has this batch ('.$payout->sender_batch_id.'): reconcile in the PayPal dashboard, then mark the commission paid manually if it was paid. '.$error]);
            $this->log($payout, null, 'duplicate', 'PayPal reported the payout batch as already submitted');

            return $payout->refresh();
        }

        if ($response->clientError() && ! in_array($response->status(), [408, 409, 429], true)) {
            $this->transition($payout, ReferralPayoutStatus::Failed, 'REJECTED', [], 'PayPal rejected the payout: '.$error);

            return $payout->refresh();
        }

        $payout->update(['failure_reason' => 'PayPal returned HTTP '.$response->status().' — outcome unknown. Use "Refresh status" to retry safely. '.$error]);

        return $payout->refresh();
    }

    // ------------------------------------------------------------------
    // Step 3 — outcome (refresh / webhook)
    // ------------------------------------------------------------------

    /** Re-check one live PayPal attempt with PayPal. */
    public function refresh(ReferralPayout $payout, ?User $by = null): ReferralPayout
    {
        if (! $payout->isPaypal() || ! in_array($payout->status, [ReferralPayoutStatus::Processing, ReferralPayoutStatus::Unclaimed], true)) {
            return $payout;
        }

        if (! $this->paypalConfigured()) {
            throw ValidationException::withMessages(['payout' => 'PayPal is not configured — cannot refresh the payout status.']);
        }

        if ($payout->payout_batch_id === null) {
            if ($payout->provider_status === 'DUPLICATE') {
                return $payout;
            }

            $this->log($payout, $by, 'resubmitted', 'PayPal payout re-submitted with the same sender_batch_id');

            return $this->submit($payout);
        }

        try {
            $response = $this->paypal->getPayoutBatch($payout->payout_batch_id);
        } catch (Throwable $e) {
            Log::warning('PayPal payout status refresh failed', ['referral_payout_id' => $payout->getKey(), 'error' => $e->getMessage()]);

            return $payout;
        }

        $payout->update(['last_checked_at' => now()]);

        if ($response->failed()) {
            Log::warning('PayPal payout status refresh failed', ['referral_payout_id' => $payout->getKey(), 'status' => $response->status()]);

            return $payout;
        }

        $batchStatus = strtoupper((string) $response->json('batch_header.batch_status', ''));
        $items = collect((array) $response->json('items', []));
        $item = $items->first(fn ($i) => (string) data_get($i, 'payout_item.sender_item_id') === (string) $payout->referral_earning_id)
            ?? $items->first();

        if (is_array($item) && filled($item['transaction_status'] ?? null)) {
            $this->applyProviderStatus($payout, strtoupper((string) $item['transaction_status']), $item, $by);
        } elseif ($batchStatus === 'DENIED') {
            $this->transition($payout, ReferralPayoutStatus::Failed, 'DENIED', [], 'PayPal denied the payout batch.', $by);
        } else {
            $payout->update(['provider_status' => $batchStatus ?: $payout->provider_status]);
        }

        return $payout->refresh();
    }

    /** @return int number of attempts checked */
    public function refreshOpen(): int
    {
        if (! $this->paypalConfigured()) {
            return 0;
        }

        $open = ReferralPayout::where('method', ReferralPayout::METHOD_PAYPAL)
            ->whereIn('status', [ReferralPayoutStatus::Processing->value, ReferralPayoutStatus::Unclaimed->value])
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($open as $payout) {
            try {
                $this->refresh($payout);
            } catch (Throwable $e) {
                Log::warning('Referral payout refresh failed', ['referral_payout_id' => $payout->getKey(), 'error' => $e->getMessage()]);
            }
        }

        return $open->count();
    }

    /**
     * A verified PAYMENT.PAYOUTS* webhook (PayPalGateway::handleWebhook()).
     *
     * @param  array<string, mixed>  $resource
     */
    public function handlePayPalWebhook(string $eventType, array $resource): string
    {
        $payout = $this->findPayoutForResource($resource);

        if ($payout === null) {
            return 'ignored';
        }

        if ($eventType === 'PAYMENT.PAYOUTSBATCH.DENIED') {
            $this->transition($payout, ReferralPayoutStatus::Failed, 'DENIED', [], 'PayPal denied the payout batch.');

            return 'ok';
        }

        $providerStatus = self::EVENT_STATUS_MAP[$eventType] ?? strtoupper((string) ($resource['transaction_status'] ?? ''));

        if ($providerStatus === '' || ! isset(self::PROVIDER_STATUS_MAP[$providerStatus])) {
            return 'ignored';
        }

        $this->applyProviderStatus($payout, $providerStatus, $resource);

        return 'ok';
    }

    /** @param  array<string, mixed>  $resource */
    private function findPayoutForResource(array $resource): ?ReferralPayout
    {
        $itemId = $resource['payout_item_id'] ?? null;
        if (filled($itemId) && ($p = ReferralPayout::where('payout_item_id', $itemId)->first())) {
            return $p;
        }

        $senderBatchId = $resource['sender_batch_id'] ?? data_get($resource, 'batch_header.sender_batch_header.sender_batch_id');
        if (filled($senderBatchId) && ($p = ReferralPayout::where('sender_batch_id', $senderBatchId)->first())) {
            return $p;
        }

        $batchId = $resource['payout_batch_id'] ?? data_get($resource, 'batch_header.payout_batch_id');
        if (filled($batchId)) {
            $senderItemId = (string) data_get($resource, 'payout_item.sender_item_id', '');

            return ReferralPayout::where('payout_batch_id', $batchId)
                ->when($senderItemId !== '', fn ($q) => $q->where('referral_earning_id', (int) $senderItemId))
                ->first();
        }

        return null;
    }

    /** @param  array<string, mixed>  $item */
    private function applyProviderStatus(ReferralPayout $payout, string $providerStatus, array $item, ?User $by = null): void
    {
        $target = self::PROVIDER_STATUS_MAP[$providerStatus] ?? null;
        if ($target === null) {
            return;
        }

        $reason = null;
        if ($target === ReferralPayoutStatus::Failed) {
            $reason = (string) (data_get($item, 'errors.message') ?: data_get($item, 'errors.name') ?: 'PayPal reported the payout as '.$providerStatus.'.');
        }

        $this->transition($payout, $target, $providerStatus, $item, $reason, $by);
    }

    /**
     * The one place an attempt's status changes after submission. Locked;
     * illegal or duplicate transitions are no-ops.
     *
     * @param  array<string, mixed>  $item
     */
    private function transition(ReferralPayout $payout, ReferralPayoutStatus $target, string $providerStatus, array $item, ?string $reason, ?User $by = null): void
    {
        $changed = DB::transaction(function () use ($payout, $target, $providerStatus, $item, $reason): ?ReferralPayoutStatus {
            $locked = ReferralPayout::whereKey($payout->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            $ids = array_filter([
                'payout_item_id' => $item['payout_item_id'] ?? null,
                'transaction_id' => $item['transaction_id'] ?? null,
                'payout_batch_id' => $locked->payout_batch_id ?? ($item['payout_batch_id'] ?? null),
            ]);

            $allowed = match ($from) {
                ReferralPayoutStatus::Processing, ReferralPayoutStatus::Unclaimed => $target !== $from,
                // Money came back after a success (returned / reversed).
                ReferralPayoutStatus::Succeeded => $target === ReferralPayoutStatus::Failed && in_array($providerStatus, self::REVERSAL_STATUSES, true),
                // A late success after we marked it failed.
                ReferralPayoutStatus::Failed => $target === ReferralPayoutStatus::Succeeded,
                default => false,
            };

            if (! $allowed) {
                if ($ids !== []) {
                    $locked->update($ids);
                }

                return null;
            }

            $earning = ReferralEarning::whereKey($locked->referral_earning_id)->lockForUpdate()->firstOrFail();

            if ($from === ReferralPayoutStatus::Failed
                && ReferralPayout::where('referral_earning_id', $earning->getKey())
                    ->whereKeyNot($locked->getKey())
                    ->whereIn('status', array_map(fn ($s) => $s->value, ReferralPayoutStatus::live()))
                    ->exists()) {
                // Late success while another attempt is live — a human must
                // reconcile (recover one of the two payments); never auto-resolve.
                Log::critical('Referral payout succeeded at PayPal while another payout for the same earning is live — reconcile manually.', [
                    'referral_payout_id' => $locked->getKey(), 'referral_earning_id' => $earning->getKey(),
                ]);
                $locked->update(array_merge($ids, ['provider_status' => $providerStatus, 'failure_reason' => 'PayPal reported SUCCESS after this attempt was marked failed while another payout is live — reconcile manually.']));

                return null;
            }

            $locked->update(array_merge($ids, [
                'status' => $target,
                'provider_status' => $providerStatus,
                'failure_reason' => $target === ReferralPayoutStatus::Failed ? $reason : null,
                'completed_at' => in_array($target, [ReferralPayoutStatus::Succeeded, ReferralPayoutStatus::Failed], true) ? now() : null,
            ]));

            if ($target === ReferralPayoutStatus::Succeeded) {
                $earning->markPaid($locked->decidedBy);
            } elseif ($from === ReferralPayoutStatus::Succeeded) {
                $earning->revertToApproved();
            }

            return $from;
        });

        if ($changed === null) {
            return;
        }

        $payout->refresh();
        $this->log($payout, $by, 'status_'.$target->value, 'PayPal payout '.$changed->value.' → '.$target->value, [
            'provider_status' => $providerStatus,
            'reason' => $reason,
        ]);

        if (in_array($target, [ReferralPayoutStatus::Succeeded, ReferralPayoutStatus::Failed, ReferralPayoutStatus::Unclaimed], true)) {
            $this->notifyReferrer($payout);
        }
    }

    // ------------------------------------------------------------------
    // Manual (MoMo / bank)
    // ------------------------------------------------------------------

    public function markPaidManually(ReferralEarning $earning, User $by, string $referenceNote): ReferralPayout
    {
        self::authorize($by);

        $referenceNote = trim($referenceNote);
        if (mb_strlen($referenceNote) < 5) {
            throw ValidationException::withMessages(['reference_note' => 'Enter the MoMo / bank transfer reference (at least 5 characters).']);
        }

        $payout = DB::transaction(function () use ($earning, $by, $referenceNote): ReferralPayout {
            $earning = ReferralEarning::whereKey($earning->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($earning->status, [ReferralEarningStatus::Pending, ReferralEarningStatus::Approved], true)) {
                throw ValidationException::withMessages(['payout' => 'Only pending or approved, unpaid commissions can be marked paid.']);
            }

            $live = ReferralPayout::where('referral_earning_id', $earning->getKey())
                ->whereIn('status', array_map(fn ($s) => $s->value, ReferralPayoutStatus::live()))
                ->lockForUpdate()
                ->first();

            // A PayPal attempt whose outcome is unknown / duplicate can be
            // closed by a human who reconciled it; anything PayPal is still
            // actively handling cannot.
            if ($live !== null && ! ($live->isPaypal() && $live->status === ReferralPayoutStatus::Processing && $live->payout_batch_id === null)) {
                throw ValidationException::withMessages(['payout' => 'This commission has a PayPal payout '.strtolower($live->status->label()).' — resolve it first.']);
            }

            try {
                if ($live !== null) {
                    $live->update([
                        'status' => ReferralPayoutStatus::Succeeded,
                        'reference_note' => $referenceNote,
                        'completed_at' => now(),
                        'decided_by' => $live->decided_by ?? $by->getKey(),
                    ]);
                    $payout = $live;
                } else {
                    $payout = ReferralPayout::create([
                        'referral_earning_id' => $earning->getKey(),
                        'method' => ReferralPayout::METHOD_MANUAL,
                        'status' => ReferralPayoutStatus::Succeeded,
                        'amount' => $earning->amount,
                        'currency' => strtoupper((string) $earning->currency),
                        'reference_note' => $referenceNote,
                        'requested_by' => $by->getKey(),
                        'requested_at' => now(),
                        'decided_by' => $by->getKey(),
                        'decided_at' => now(),
                        'completed_at' => now(),
                    ]);
                }
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['payout' => 'This commission has already been paid.']);
            }

            $earning->markPaid($by);

            return $payout;
        });

        $this->log($payout, $by, 'manual_paid', 'Referral commission marked paid manually', ['reference_note' => $referenceNote]);
        $this->notifyReferrer($payout);

        return $payout;
    }

    // ------------------------------------------------------------------

    /**
     * Bulk step 1. Each earning is requested independently; ineligible ones
     * are reported, not fatal.
     *
     * @param  Collection<int, ReferralEarning>  $earnings
     * @return array{ok: int, errors: list<string>}
     */
    public function requestMany(Collection $earnings, User $by): array
    {
        $ok = 0;
        $errors = [];

        foreach ($earnings as $earning) {
            try {
                $this->requestPayPalPayout($earning, $by);
                $ok++;
            } catch (ValidationException $e) {
                $errors[] = '#'.$earning->getKey().': '.collect($e->errors())->flatten()->first();
            }
        }

        return ['ok' => $ok, 'errors' => $errors];
    }

    /** Money-moving actions need finance authority, whatever surface calls them. */
    private static function authorize(User $user): void
    {
        if (! $user->can('payments.manage')) {
            throw ValidationException::withMessages(['payout' => 'You need the payments.manage permission to pay referral commissions.']);
        }
    }

    private function notifyReferrer(ReferralPayout $payout): void
    {
        $referrer = $payout->earning?->referrer;
        if ($referrer === null) {
            return;
        }

        DB::afterCommit(fn () => $referrer->notify(new ReferralPayoutUpdatedNotification($payout->fresh())));
    }

    /** @param  array<string, mixed>  $extra */
    private function log(ReferralPayout $payout, ?User $by, string $event, string $message, array $extra = []): void
    {
        activity('referral_payout')
            ->performedOn($payout)
            ->causedBy($by ?? auth()->user())
            ->event($event)
            ->withProperties(array_merge([
                'referral_earning_id' => $payout->referral_earning_id,
                'method' => $payout->method,
                'status' => $payout->status?->value,
                'amount' => (string) $payout->amount,
                'currency' => $payout->currency,
                'receiver' => $payout->maskedReceiver(),
                'sender_batch_id' => $payout->sender_batch_id,
                'payout_batch_id' => $payout->payout_batch_id,
            ], array_filter($extra, fn ($v) => $v !== null)))
            ->log($message);
    }

    private static function errorMessage(HttpResponse $response): string
    {
        $name = (string) $response->json('name', '');
        $message = (string) $response->json('message', '');
        $issues = collect((array) $response->json('details', []))->pluck('issue')->filter()->implode(', ');

        return trim($name.' '.$message.($issues !== '' ? ' ('.$issues.')' : '')) ?: 'HTTP '.$response->status();
    }
}
