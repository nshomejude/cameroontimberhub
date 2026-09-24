<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Enums\TimberLotStatus;
use App\Enums\TransformationRequestStatus;
use App\Exceptions\Api\ConflictException;
use App\Models\Company;
use App\Models\LotTransformation;
use App\Models\TimberLot;
use App\Models\TransformationRequest;
use App\Models\User;
use App\Notifications\TransformationRequestAcceptedNotification;
use App\Notifications\TransformationRequestCompletedNotification;
use App\Notifications\TransformationRequestCreatedNotification;
use App\Notifications\TransformationRequestQuotedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Transformation Request status machine — the request/accept/quote/job
 * pipeline for the Transformation Network (Api\V1\TransformationNetworkController
 * stays strictly read-only; this is the separate write-side service the
 * brief asked for). Shaped identically to OrderLifecycleService: a single
 * decision point, an ACTOR guard (403 for the wrong side) separate from a
 * MOVE guard (ConflictException/409 for an illegal transition), and every
 * write inside a DB transaction.
 *
 * Status machine (App\Enums\TransformationRequestStatus):
 *   Pending    -> Quoted, Accepted, Declined, Cancelled
 *   Quoted     -> Accepted, Declined, Cancelled
 *   Accepted   -> InProgress, Cancelled
 *   InProgress -> Completed
 *
 * Who may do what:
 *   provider only  : accept, decline, quote, startJob, completeJob
 *   requester only : acceptQuote, declineQuote, cancel
 *
 * A non-participant never reaches a specific request: every lookup re-scopes
 * to "the caller's company is the requester or the provider" and 404s
 * otherwise (enumeration-safety, same convention as SupplierApiScope /
 * BuyerApiScope). A participant on the wrong side gets 403.
 */
class TransformationRequestService
{
    private const TRANSITIONS = [
        'pending' => ['quoted', 'accepted', 'declined', 'cancelled'],
        'quoted' => ['accepted', 'declined', 'cancelled'],
        'accepted' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed'],
        'completed' => [],
        'declined' => [],
        'cancelled' => [],
    ];

    /* --------------------------------------------------------------- scope */

    /** The caller's own company — the first membership, mirroring SupplierApiScope::company(). */
    public function company(User $user): ?Company
    {
        return $user->companies()->first();
    }

    /** Requests the caller's company MADE, newest first. */
    public function sent(User $user): Builder
    {
        $companyId = $this->company($user)?->getKey();

        return TransformationRequest::query()
            ->where('requester_company_id', $companyId)
            ->orderByDesc('created_at');
    }

    /** Requests made TO the caller's company, newest first. */
    public function received(User $user): Builder
    {
        $companyId = $this->company($user)?->getKey();

        return TransformationRequest::query()
            ->where('provider_company_id', $companyId)
            ->orderByDesc('created_at');
    }

    /**
     * One request by reference, for either side of it — 404 for a
     * non-participant, exactly like OrderLifecycleService::threadOrder().
     */
    public function forParticipant(User $user, string $reference): TransformationRequest
    {
        $companyId = $this->company($user)?->getKey();

        return TransformationRequest::query()
            ->where('reference_code', $reference)
            ->where(fn (Builder $q) => $q
                ->where('requester_company_id', $companyId)
                ->orWhere('provider_company_id', $companyId))
            ->firstOr(fn () => abort(404));
    }

    private function assertRequester(User $user, TransformationRequest $request): void
    {
        $companyId = $this->company($user)?->getKey();

        abort_unless((int) $request->requester_company_id === (int) $companyId, 403, 'Only the requesting company can do that.');
    }

    private function assertProvider(User $user, TransformationRequest $request): void
    {
        $companyId = $this->company($user)?->getKey();

        abort_unless((int) $request->provider_company_id === (int) $companyId, 403, 'Only the provider company can do that.');
    }

    /* -------------------------------------------------------------- create */

    /** @param array<string, mixed> $data */
    public function create(User $requester, array $data): TransformationRequest
    {
        $requesterCompany = $this->company($requester);

        abort_if($requesterCompany === null, 422, 'You must belong to a company to send a transformation request.');

        $provider = Company::query()
            ->where('slug', $data['provider_slug'])
            ->whereIn('type', [OrganisationType::Processor->value, OrganisationType::Manufacturer->value])
            ->where('status', CompanyStatus::Verified->value)
            ->first();

        if ($provider === null) {
            throw new ConflictException(
                'The selected provider is not a verified transformation-network company.',
                'provider_not_eligible',
            );
        }

        if ((int) $provider->getKey() === (int) $requesterCompany->getKey()) {
            throw new ConflictException('A company cannot send a transformation request to itself.', 'self_request');
        }

        return DB::transaction(function () use ($requester, $requesterCompany, $provider, $data) {
            /** @var TransformationRequest $request */
            $request = TransformationRequest::create([
                'requester_company_id' => $requesterCompany->getKey(),
                'provider_company_id' => $provider->getKey(),
                'requested_by_user_id' => $requester->getKey(),
                'service' => $data['service'],
                'species_id' => $data['species_id'] ?? null,
                'volume_m3' => $data['volume_m3'],
                'input_description' => $data['input_description'] ?? null,
                'target_spec' => $data['target_spec'] ?? null,
                'deadline' => $data['deadline'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => TransformationRequestStatus::Pending->value,
                'timeline' => [],
            ]);

            $request->appendTimeline(TransformationRequestStatus::Pending);
            $request->save();

            $this->notifyCompany($provider, new TransformationRequestCreatedNotification($request));

            return $request->refresh();
        });
    }

    /* --------------------------------------------------------- provider side */

    /** Provider accepts the job directly (no quote step). */
    public function accept(User $provider, TransformationRequest $request): TransformationRequest
    {
        $this->assertProvider($provider, $request);

        return $this->transition($request, TransformationRequestStatus::Accepted, function (TransformationRequest $r) {
            $r->accepted_at = now();
        }, notify: fn (TransformationRequest $r) => $this->notifyCompany(
            $r->requesterCompany,
            new TransformationRequestAcceptedNotification($r),
        ));
    }

    public function decline(User $provider, TransformationRequest $request, string $reason): TransformationRequest
    {
        $this->assertProvider($provider, $request);

        if (trim($reason) === '') {
            throw new ConflictException('A decline reason is required.', 'reason_required');
        }

        return $this->transition($request, TransformationRequestStatus::Declined, function (TransformationRequest $r) use ($reason) {
            $r->decline_reason = trim($reason);
        });
    }

    /** @param array<string, mixed> $data */
    public function quote(User $provider, TransformationRequest $request, array $data): TransformationRequest
    {
        $this->assertProvider($provider, $request);

        return $this->transition($request, TransformationRequestStatus::Quoted, function (TransformationRequest $r) use ($data) {
            $r->quote_amount = $data['amount'];
            $r->quote_currency = strtoupper($data['currency']);
            $r->quote_lead_time_days = $data['lead_time_days'] ?? null;
            $r->quote_notes = $data['notes'] ?? null;
        }, notify: fn (TransformationRequest $r) => $this->notifyCompany(
            $r->requesterCompany,
            new TransformationRequestQuotedNotification($r),
        ));
    }

    public function startJob(User $provider, TransformationRequest $request): TransformationRequest
    {
        $this->assertProvider($provider, $request);

        return $this->transition($request, TransformationRequestStatus::InProgress, function (TransformationRequest $r) {
            $r->started_at = now();
        });
    }

    /**
     * Completes the job and writes the real LotTransformation ledger row.
     *
     * TimberLot integration decision (brief point 5): the mobile contract
     * is volume-only at request time — the requester never supplies a
     * specific TimberLot. Rather than skip mass-balance traceability (the
     * entire point of this feature) or silently invent numbers, this method:
     *
     *   1. Creates an INPUT TimberLot owned by the REQUESTER's company for
     *      the request's declared `volume_m3` (status: InProcessing — it is
     *      being consumed by this job), letting the provider optionally
     *      override the actual input volume actioned via `$inputVolume`.
     *   2. Creates an OUTPUT TimberLot owned by the PROVIDER's company for
     *      the actual output volume the provider reports at completion
     *      (`$outputVolume` — defaults to the input volume, i.e. "no
     *      reported loss", if the provider does not supply one).
     *   3. Calls LotTransformation::recordFor() with those two lots, which
     *      is the ledger's own arithmetic (loss = input - output).
     *
     * This keeps LotTransformation as the single mass-balance authority
     * (nothing here recomputes loss/ratio) while giving every completed
     * request a real, queryable traceability link — `TimberLot::generateLotNumber()`
     * already guarantees a proper `CTH-TIM-...` lot number for both rows.
     */
    public function completeJob(
        User $provider,
        TransformationRequest $request,
        ?float $inputVolume = null,
        ?float $outputVolume = null,
        ?string $notes = null,
    ): TransformationRequest {
        $this->assertProvider($provider, $request);

        if ($request->status !== TransformationRequestStatus::InProgress) {
            throw new ConflictException(
                "This request is {$request->status->value} and cannot be completed.",
                'illegal_transition',
            );
        }

        return DB::transaction(function () use ($request, $inputVolume, $outputVolume, $notes) {
            $inputVol = $inputVolume ?? (float) $request->volume_m3;
            $outputVol = $outputVolume ?? $inputVol;

            $inputLot = TimberLot::create([
                'company_id' => $request->requester_company_id,
                'species_id' => $request->species_id,
                'quantity' => $inputVol,
                'volume_m3' => $inputVol,
                'status' => TimberLotStatus::InProcessing->value,
                'processing_method' => $request->service,
            ]);

            $outputLot = TimberLot::create([
                'company_id' => $request->provider_company_id,
                'species_id' => $request->species_id,
                'quantity' => $outputVol,
                'volume_m3' => $outputVol,
                'status' => TimberLotStatus::Available->value,
                'processing_method' => $request->service,
            ]);

            $transformation = LotTransformation::recordFor(
                processorCompanyId: (int) $request->provider_company_id,
                transformationType: $request->service,
                inputs: [['lot' => $inputLot, 'quantity' => $inputVol]],
                outputs: [['lot' => $outputLot, 'quantity' => $outputVol]],
                processedAt: now(),
                notes: $notes,
            );

            $request->lot_transformation_id = $transformation->getKey();
            $request->completed_at = now();
            $request->status = TransformationRequestStatus::Completed;
            $request->appendTimeline(TransformationRequestStatus::Completed);
            $request->save();

            $this->notifyCompany(
                $request->requesterCompany,
                new TransformationRequestCompletedNotification($request),
            );

            return $request->refresh();
        });
    }

    /* ------------------------------------------------------- requester side */

    public function acceptQuote(User $requester, TransformationRequest $request): TransformationRequest
    {
        $this->assertRequester($requester, $request);

        if ($request->status !== TransformationRequestStatus::Quoted) {
            throw new ConflictException(
                "This request is {$request->status->value} and has no quote to accept.",
                'illegal_transition',
            );
        }

        return $this->transition($request, TransformationRequestStatus::Accepted, function (TransformationRequest $r) {
            $r->accepted_at = now();
        }, notify: fn (TransformationRequest $r) => $this->notifyCompany(
            $r->providerCompany,
            new TransformationRequestAcceptedNotification($r),
        ));
    }

    public function declineQuote(User $requester, TransformationRequest $request): TransformationRequest
    {
        $this->assertRequester($requester, $request);

        if ($request->status !== TransformationRequestStatus::Quoted) {
            throw new ConflictException(
                "This request is {$request->status->value} and has no quote to decline.",
                'illegal_transition',
            );
        }

        return $this->transition($request, TransformationRequestStatus::Declined);
    }

    /** Cancel before the provider has started the job — i.e. while Pending, Quoted or Accepted. */
    public function cancel(User $requester, TransformationRequest $request): TransformationRequest
    {
        $this->assertRequester($requester, $request);

        if (! in_array($request->status, [
            TransformationRequestStatus::Pending,
            TransformationRequestStatus::Quoted,
            TransformationRequestStatus::Accepted,
        ], true)) {
            throw new ConflictException(
                "This request is {$request->status->value} and can no longer be cancelled.",
                'illegal_transition',
            );
        }

        return $this->transition($request, TransformationRequestStatus::Cancelled, function (TransformationRequest $r) {
            $r->cancelled_at = now();
        });
    }

    /* ------------------------------------------------------------- actions */

    /**
     * Server-computed `actions[]` for the CALLER only — same convention the
     * mobile contract already uses on MessageResource/OrderResource: only
     * what THIS side can currently do, never the other side's moves.
     *
     * @return list<array<string, mixed>>
     */
    public function actionsFor(User $user, TransformationRequest $request): array
    {
        $companyId = $this->company($user)?->getKey();
        $isProvider = (int) $request->provider_company_id === (int) $companyId;
        $isRequester = (int) $request->requester_company_id === (int) $companyId;
        $reference = $request->reference_code;
        $base = "/api/v1/transformation/requests/{$reference}";

        $actions = [];

        if ($isProvider) {
            if ($request->status === TransformationRequestStatus::Pending) {
                $actions[] = ['key' => 'accept', 'label' => 'Accept', 'method' => 'POST', 'path' => "{$base}/accept"];
                $actions[] = [
                    'key' => 'decline', 'label' => 'Decline', 'method' => 'POST', 'path' => "{$base}/decline",
                    'fields' => ['reason'],
                ];
                $actions[] = [
                    'key' => 'quote', 'label' => 'Send quote', 'method' => 'POST', 'path' => "{$base}/quote",
                    'fields' => ['amount', 'currency', 'lead_time_days', 'notes'],
                ];
            }

            if ($request->status === TransformationRequestStatus::Accepted) {
                $actions[] = ['key' => 'startJob', 'label' => 'Start job', 'method' => 'POST', 'path' => "{$base}/start"];
            }

            if ($request->status === TransformationRequestStatus::InProgress) {
                $actions[] = [
                    'key' => 'completeJob', 'label' => 'Complete job', 'method' => 'POST', 'path' => "{$base}/complete",
                    'fields' => ['input_volume_m3', 'output_volume_m3', 'notes'],
                ];
            }
        }

        if ($isRequester) {
            if ($request->status === TransformationRequestStatus::Quoted) {
                $actions[] = ['key' => 'acceptQuote', 'label' => 'Accept quote', 'method' => 'POST', 'path' => "{$base}/accept-quote"];
                $actions[] = ['key' => 'declineQuote', 'label' => 'Decline quote', 'method' => 'POST', 'path' => "{$base}/decline-quote"];
            }

            if (in_array($request->status, [
                TransformationRequestStatus::Pending,
                TransformationRequestStatus::Quoted,
                TransformationRequestStatus::Accepted,
            ], true)) {
                $actions[] = ['key' => 'cancel', 'label' => 'Cancel', 'method' => 'POST', 'path' => "{$base}/cancel"];
            }
        }

        return $actions;
    }

    /* -------------------------------------------------------------- plumbing */

    private function transition(
        TransformationRequest $request,
        TransformationRequestStatus $to,
        ?callable $apply = null,
        ?callable $notify = null,
    ): TransformationRequest {
        $from = $request->status;

        if (! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw new ConflictException("Illegal transformation request transition {$from->value} -> {$to->value}.", 'illegal_transition');
        }

        return DB::transaction(function () use ($request, $to, $apply, $notify) {
            if ($apply !== null) {
                $apply($request);
            }

            $request->status = $to;
            $request->appendTimeline($to);
            $request->save();

            $refreshed = $request->refresh();

            if ($notify !== null) {
                $notify($refreshed);
            }

            return $refreshed;
        });
    }

    private function notifyCompany(?Company $company, $notification): void
    {
        if ($company === null) {
            return;
        }

        $company->loadMissing('users');
        $company->users->each(fn (User $user) => $user->notify($notification));
    }
}
