<?php

namespace App\Services\Commission;

use App\Enums\CommissionDepositMethod;
use App\Enums\CommissionDepositStatus;
use App\Enums\CommissionStatementStatus;
use App\Exceptions\Api\CommissionOverdueException;
use App\Models\CommissionDeposit;
use App\Models\CommissionStatement;
use App\Models\Company;
use App\Models\User;
use App\Notifications\CommissionDepositReviewedNotification;
use App\Notifications\CommissionStatementReminderNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Commission COLLECTION workflow (owner decision 2026-10-01): suppliers pay
 * their monthly commission statements by manual deposit — MTN MoMo / Orange
 * Money transfer to the platform's number or a bank deposit / transfer —
 * and finance verifies each one. There is no automatic debit.
 *
 *  - `report()`        supplier reports a deposit (pending verification),
 *                      optional proof on the private `documents` disk;
 *  - `confirm()`       finance confirms the amount actually received →
 *                      statement partially_paid / paid;
 *  - `reject()`        finance rejects with a reason (supplier notified);
 *  - `recordDeposit()` finance records money that arrived without a report;
 *  - `void()`          finance withdraws a statement (releases its orders);
 *  - `processDueDates()` daily: due-soon reminder + overdue flag/notice;
 *  - `summary()` / `assertMayQuote()` outstanding balance + the optional
 *                      overdue enforcement (OFF unless
 *                      `timber.commission.block_on_overdue_days` is set).
 *
 * Every money-moving step is gated by `payments.manage`, runs under a row
 * lock on the statement, and is written to the `commission_collection`
 * activity log. Validation failures are ValidationExceptions so the Filament
 * actions and the API share one rule set (422 on the API).
 */
class CommissionCollectionService
{
    public const PROOF_DISK = 'documents';

    public const PROOF_MAX_KB = 5120;

    public const PROOF_MIMES = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /* ------------------------------------------------------------ supplier */

    /**
     * A supplier reports a deposit against one of its statements.
     *
     * `$proof` is an UploadedFile (API) or a path already stored on the
     * private `documents` disk by the Filament upload field.
     *
     * @param  array<string, mixed>  $data  method, amount, currency, transaction_reference, paid_on, notes?
     */
    public function report(CommissionStatement $statement, User $by, array $data, UploadedFile|string|null $proof = null): CommissionDeposit
    {
        // A pre-stored path (Filament) is client-supplied state: accept only a
        // fresh upload inside this company's proof folder, never an arbitrary
        // or already-referenced path on the private disk.
        if (is_string($proof)) {
            $proof = $this->ownedFreshProofPath($statement, $proof);
        }

        try {
            $validated = $this->validateDeposit($statement, $data, $proof instanceof UploadedFile ? $proof : null);
        } catch (ValidationException $e) {
            // A file the Filament field already stored must not be orphaned.
            if (is_string($proof)) {
                Storage::disk(self::PROOF_DISK)->delete($proof);
            }

            throw $e;
        }

        $proofMeta = $this->storeProof($statement, $proof);

        try {
            return DB::transaction(function () use ($statement, $by, $validated, $proofMeta): CommissionDeposit {
                $locked = CommissionStatement::whereKey($statement->getKey())->lockForUpdate()->firstOrFail();
                $this->assertOpen($locked);
                $this->assertReferenceUnused($validated['method'], $validated['transaction_reference']);

                $deposit = CommissionDeposit::create([
                    'commission_statement_id' => $locked->getKey(),
                    'company_id' => $locked->company_id,
                    'method' => $validated['method'],
                    'source' => CommissionDeposit::SOURCE_SUPPLIER,
                    'status' => CommissionDepositStatus::Pending,
                    'currency' => $locked->currency->value,
                    'amount' => bcadd((string) $validated['amount'], '0', 2),
                    'transaction_reference' => trim((string) $validated['transaction_reference']),
                    'reference_key' => CommissionDeposit::normaliseReference((string) $validated['transaction_reference']),
                    'paid_on' => $validated['paid_on'],
                    'notes' => $validated['notes'] ?? null,
                    'reported_by' => $by->getKey(),
                ] + $proofMeta);

                $this->log($deposit, 'reported', $by, ['amount' => $deposit->amount, 'method' => $deposit->method->value, 'reference' => $deposit->transaction_reference]);

                return $deposit;
            });
        } catch (\Throwable $e) {
            // Never leave an orphaned proof file behind a failed report.
            if (($proofMeta['proof_path'] ?? null) !== null) {
                Storage::disk(self::PROOF_DISK)->delete($proofMeta['proof_path']);
            }

            if ($e instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['transaction_reference' => self::duplicateMessage()]);
            }

            throw $e;
        }
    }

    /* ------------------------------------------------------------- finance */

    /**
     * Confirm a pending deposit: records the amount actually received
     * (defaults to the reported amount) and pays the statement down.
     */
    public function confirm(CommissionDeposit $deposit, User $by, float|string|null $amountReceived = null, ?string $note = null): CommissionDeposit
    {
        $this->authorize($by);

        $deposit = DB::transaction(function () use ($deposit, $by, $amountReceived, $note): CommissionDeposit {
            $locked = CommissionDeposit::whereKey($deposit->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['deposit' => 'Only a deposit pending verification can be confirmed.']);
            }

            $statement = CommissionStatement::whereKey($locked->commission_statement_id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($statement);

            $amount = bcadd((string) ($amountReceived ?? $locked->amount), '0', 2);

            if (bccomp($amount, '0', 2) <= 0) {
                throw ValidationException::withMessages(['amount_received' => 'The amount received must be greater than zero.']);
            }

            if (bccomp($amount, $statement->outstanding(), 2) > 0) {
                throw ValidationException::withMessages([
                    'amount_received' => 'The amount received ('.$statement->money($amount).') exceeds what is still due on '
                        .$statement->statement_number.' ('.$statement->money($statement->outstanding()).'). Record only the amount due '
                        .'and settle any overpayment with the supplier separately.',
                ]);
            }

            $locked->forceFill([
                'status' => CommissionDepositStatus::Confirmed,
                'amount_received' => $amount,
                'reviewed_by' => $by->getKey(),
                'reviewed_at' => now(),
                'notes' => $this->appendNote($locked->notes, $note),
            ])->save();

            $this->applyPayment($statement, $amount);

            $this->log($locked, 'confirmed', $by, [
                'amount_received' => $amount,
                'statement_status' => $statement->status->value,
                'outstanding' => $statement->outstanding(),
            ]);

            $this->notifyCompany($statement, new CommissionDepositReviewedNotification($locked));

            return $locked;
        });

        return $deposit->refresh();
    }

    /** Reject a pending deposit with a reason the supplier will see. */
    public function reject(CommissionDeposit $deposit, User $by, string $reason): CommissionDeposit
    {
        $this->authorize($by);

        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Give the supplier a reason for the rejection.']);
        }

        return DB::transaction(function () use ($deposit, $by, $reason): CommissionDeposit {
            $locked = CommissionDeposit::whereKey($deposit->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['deposit' => 'Only a deposit pending verification can be rejected.']);
            }

            $locked->forceFill([
                'status' => CommissionDepositStatus::Rejected,
                'rejection_reason' => Str::limit($reason, 1000, ''),
                'reviewed_by' => $by->getKey(),
                'reviewed_at' => now(),
            ])->save();

            $this->log($locked, 'rejected', $by, ['reason' => $reason]);

            $this->notifyCompany($locked->statement, new CommissionDepositReviewedNotification($locked));

            return $locked;
        });
    }

    /**
     * Finance records a deposit that arrived without a supplier report (seen
     * on the MoMo / bank statement) — created and confirmed in one step.
     *
     * @param  array<string, mixed>  $data  method, amount, transaction_reference, paid_on, notes?
     */
    public function recordDeposit(CommissionStatement $statement, User $by, array $data): CommissionDeposit
    {
        $this->authorize($by);

        $validated = $this->validateDeposit($statement, $data + ['currency' => $statement->currency->value], null);

        try {
            $deposit = DB::transaction(function () use ($statement, $by, $validated): CommissionDeposit {
                $locked = CommissionStatement::whereKey($statement->getKey())->lockForUpdate()->firstOrFail();
                $this->assertOpen($locked);
                $this->assertReferenceUnused($validated['method'], $validated['transaction_reference']);

                $deposit = CommissionDeposit::create([
                    'commission_statement_id' => $locked->getKey(),
                    'company_id' => $locked->company_id,
                    'method' => $validated['method'],
                    'source' => CommissionDeposit::SOURCE_ADMIN,
                    'status' => CommissionDepositStatus::Pending,
                    'currency' => $locked->currency->value,
                    'amount' => bcadd((string) $validated['amount'], '0', 2),
                    'transaction_reference' => trim((string) $validated['transaction_reference']),
                    'reference_key' => CommissionDeposit::normaliseReference((string) $validated['transaction_reference']),
                    'paid_on' => $validated['paid_on'],
                    'notes' => $validated['notes'] ?? null,
                    'reported_by' => $by->getKey(),
                ]);

                $this->log($deposit, 'recorded', $by, ['amount' => $deposit->amount, 'method' => $deposit->method->value, 'reference' => $deposit->transaction_reference]);

                return $this->confirm($deposit, $by);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['transaction_reference' => self::duplicateMessage()]);
        }

        return $deposit;
    }

    /**
     * Withdraw a statement issued in error. Only a statement nothing has been
     * paid against can be voided (a paid amount must stay attached to a live
     * statement). Its lines are released so the orders are billed again on
     * the next run, pending deposits are rejected, and the period may be
     * re-issued (`commission:issue-statements --month=`).
     */
    public function void(CommissionStatement $statement, User $by, string $reason): CommissionStatement
    {
        $this->authorize($by);

        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to void a statement.']);
        }

        return DB::transaction(function () use ($statement, $by, $reason): CommissionStatement {
            $locked = CommissionStatement::whereKey($statement->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isVoid()) {
                throw ValidationException::withMessages(['statement' => 'This statement is already void.']);
            }

            if (bccomp((string) $locked->amount_paid, '0', 2) > 0) {
                throw ValidationException::withMessages(['statement' => 'A statement with confirmed payments cannot be voided.']);
            }

            $locked->forceFill([
                'status' => CommissionStatementStatus::Void,
                'voided_at' => now(),
                'voided_by' => $by->getKey(),
                'void_reason' => Str::limit($reason, 1000, ''),
            ])->save();

            $locked->lines()->whereNull('voided_at')->update(['voided_at' => now()]);

            $locked->deposits()->where('status', CommissionDepositStatus::Pending->value)->get()
                ->each(fn (CommissionDeposit $d) => $this->reject($d, $by, 'Statement '.$locked->statement_number.' was voided: '.$reason));

            activity('commission_collection')
                ->performedOn($locked)
                ->causedBy($by)
                ->event('statement_voided')
                ->withProperties(['statement_number' => $locked->statement_number, 'reason' => $reason])
                ->log("Commission statement {$locked->statement_number} voided");

            return $locked;
        });
    }

    /* --------------------------------------------------------------- daily */

    /**
     * Flip past-due statements to `overdue` (notifying once) and send the
     * one-off "due soon" reminder `reminder_days_before` days ahead.
     * Idempotent: each notice is stamped and never repeated.
     *
     * @return array{overdue: int, reminded: int}
     */
    public function processDueDates(): array
    {
        $overdue = 0;
        $reminded = 0;
        $today = today();

        CommissionStatement::query()
            ->open()
            ->whereDate('due_date', '<', $today)
            ->where(fn ($q) => $q->where('status', '!=', CommissionStatementStatus::Overdue->value)->orWhereNull('overdue_notified_at'))
            ->with('company.users')
            ->get()
            ->each(function (CommissionStatement $statement) use (&$overdue): void {
                DB::transaction(function () use ($statement, &$overdue): void {
                    $locked = CommissionStatement::whereKey($statement->getKey())->lockForUpdate()->first();

                    if ($locked === null || ! $locked->isOpen()) {
                        return;
                    }

                    $locked->status = CommissionStatementStatus::Overdue;

                    if ($locked->overdue_notified_at === null) {
                        $locked->overdue_notified_at = now();
                        $this->notifyCompany($statement, new CommissionStatementReminderNotification($locked, CommissionStatementReminderNotification::OVERDUE));
                        $overdue++;
                    }

                    $locked->save();
                });
            });

        $leadDays = max(0, (int) config('timber.commission.reminder_days_before', 3));

        CommissionStatement::query()
            ->whereIn('status', [CommissionStatementStatus::Issued->value, CommissionStatementStatus::PartiallyPaid->value])
            ->whereNull('due_reminder_sent_at')
            ->whereDate('due_date', '>=', $today)
            ->whereDate('due_date', '<=', $today->copy()->addDays($leadDays))
            ->with('company.users')
            ->get()
            ->each(function (CommissionStatement $statement) use (&$reminded): void {
                $statement->forceFill(['due_reminder_sent_at' => now()])->save();
                $this->notifyCompany($statement, new CommissionStatementReminderNotification($statement, CommissionStatementReminderNotification::DUE_SOON));
                $reminded++;
            });

        return ['overdue' => $overdue, 'reminded' => $reminded];
    }

    /* ------------------------------------------------------ balances / gate */

    /**
     * Outstanding balance per currency for a company (never summed across
     * currencies), for the supplier summary card / API.
     *
     * @return array{balances: list<array{currency: string, outstanding: string, outstanding_formatted: string, open_statements: int, overdue: bool, next_due_date: ?string, pending_deposits: string}>, overdue: bool, quoting_blocked: bool, block_on_overdue_days: ?int}
     */
    public function summary(Company $company): array
    {
        $open = CommissionStatement::query()->forCompany($company)->open()->get();
        $pending = CommissionDeposit::query()
            ->where('company_id', $company->getKey())
            ->where('status', CommissionDepositStatus::Pending->value)
            ->get(['currency', 'amount'])
            ->groupBy(fn (CommissionDeposit $d) => $d->currency->value);

        $balances = $open->groupBy(fn (CommissionStatement $s) => $s->currency->value)
            ->map(function ($statements, string $currency) use ($pending): array {
                $outstanding = $statements->reduce(fn (string $c, CommissionStatement $s) => bcadd($c, $s->outstanding(), 2), '0.00');

                return [
                    'currency' => $currency,
                    'outstanding' => $outstanding,
                    'outstanding_formatted' => CommissionStatement::format($outstanding, $currency),
                    'open_statements' => $statements->count(),
                    'overdue' => $statements->contains(fn (CommissionStatement $s) => $s->isPastDue()),
                    'next_due_date' => $statements->min(fn (CommissionStatement $s) => $s->due_date->toDateString()),
                    'pending_deposits' => ($pending[$currency] ?? collect())
                        ->reduce(fn (string $c, CommissionDeposit $d) => bcadd($c, (string) $d->amount, 2), '0.00'),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();

        return [
            'balances' => $balances,
            'overdue' => collect($balances)->contains('overdue', true),
            'quoting_blocked' => $this->isQuotingBlocked($company),
            'block_on_overdue_days' => self::blockOnOverdueDays(),
        ];
    }

    /** null = enforcement OFF (the default). */
    public static function blockOnOverdueDays(): ?int
    {
        $days = config('timber.commission.block_on_overdue_days');

        return is_numeric($days) && (int) $days >= 0 ? (int) $days : null;
    }

    /**
     * Optional enforcement: true only when `block_on_overdue_days` is set and
     * the company has a statement still owed more than that many days past
     * its due date. Computed from the due date itself, so it does not depend
     * on the daily job having run.
     */
    public function isQuotingBlocked(Company $company): bool
    {
        $days = self::blockOnOverdueDays();

        if ($days === null) {
            return false;
        }

        return CommissionStatement::query()
            ->forCompany($company)
            ->open()
            ->whereDate('due_date', '<', today()->subDays($days))
            ->exists();
    }

    /** @throws CommissionOverdueException */
    public function assertMayQuote(Company $company): void
    {
        if ($this->isQuotingBlocked($company)) {
            throw new CommissionOverdueException(
                'Your company has a marketplace commission statement more than '.self::blockOnOverdueDays()
                .' days overdue. Pay it and report the deposit under Commission before submitting new quotes.'
            );
        }
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateDeposit(CommissionStatement $statement, array $data, ?UploadedFile $proof): array
    {
        $validator = Validator::make(['proof' => $proof] + \Illuminate\Support\Arr::except($data, ['proof']), [
            'method' => ['required', 'string', 'in:'.implode(',', CommissionDepositMethod::values())],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'currency' => ['required', 'string', 'size:3', 'in:'.$statement->currency->value],
            'transaction_reference' => ['required', 'string', 'min:3', 'max:100'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'mimes:'.implode(',', self::PROOF_MIMES), 'max:'.self::PROOF_MAX_KB],
        ], [
            'currency.in' => 'The deposit currency must match the statement currency ('.$statement->currency->value.').',
        ]);

        $validated = $validator->validate();
        $validated['method'] = CommissionDepositMethod::from($validated['method']);
        $validated['paid_on'] = \Illuminate\Support\Carbon::parse($validated['paid_on'])->toDateString();

        return $validated;
    }

    /** @return array<string, ?string> */
    private function storeProof(CommissionStatement $statement, UploadedFile|string|null $proof): array
    {
        if ($proof === null || $proof === '') {
            return [];
        }

        if (is_string($proof)) {
            // Already on the private disk via the Filament upload field.
            return [
                'proof_disk' => self::PROOF_DISK,
                'proof_path' => $proof,
                'proof_original_name' => basename($proof),
                'proof_mime' => Storage::disk(self::PROOF_DISK)->mimeType($proof) ?: null,
            ];
        }

        $extension = mb_strtolower($proof->getClientOriginalExtension() ?: ($proof->extension() ?? 'bin'));
        $path = sprintf('commission-deposits/%d/%s.%s', $statement->company_id, Str::uuid()->toString(), $extension);
        Storage::disk(self::PROOF_DISK)->putFileAs(dirname($path), $proof, basename($path), ['visibility' => 'private']);

        $name = basename(str_replace('\\', '/', (string) $proof->getClientOriginalName()));

        return [
            'proof_disk' => self::PROOF_DISK,
            'proof_path' => $path,
            'proof_original_name' => Str::limit(preg_replace('/[^\w \-.()]+/u', '_', $name) ?: 'proof', 250, ''),
            'proof_mime' => $proof->getMimeType() ?: null,
        ];
    }

    /** The path when it is an unreferenced upload in this company's proof folder, else null. */
    private function ownedFreshProofPath(CommissionStatement $statement, string $path): ?string
    {
        $pattern = '#^commission-deposits/'.(int) $statement->company_id.'/[A-Za-z0-9_\-]+\.(jpe?g|png|webp|pdf)$#i';

        if (! preg_match($pattern, $path)
            || CommissionDeposit::where('proof_path', $path)->exists()
            || ! Storage::disk(self::PROOF_DISK)->exists($path)) {
            return null;
        }

        return $path;
    }

    private function assertOpen(CommissionStatement $statement): void
    {
        if (! $statement->isOpen()) {
            throw ValidationException::withMessages([
                'statement' => "Statement {$statement->statement_number} is {$statement->status->label()} and cannot take a payment.",
            ]);
        }
    }

    private function assertReferenceUnused(CommissionDepositMethod $method, string $reference): void
    {
        $taken = CommissionDeposit::query()
            ->where('method', $method->value)
            ->where('reference_key', CommissionDeposit::normaliseReference($reference))
            ->where('status', '!=', CommissionDepositStatus::Rejected->value)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['transaction_reference' => self::duplicateMessage()]);
        }
    }

    private static function duplicateMessage(): string
    {
        return 'This transaction reference has already been reported for this payment method.';
    }

    private function applyPayment(CommissionStatement $statement, string $amount): void
    {
        $statement->amount_paid = bcadd((string) $statement->amount_paid, $amount, 2);
        $statement->status = $statement->resolvedStatus();

        if ($statement->status === CommissionStatementStatus::Paid) {
            $statement->paid_at = now();
        }

        $statement->save();
    }

    private function authorize(User $by): void
    {
        if (! $by->can('payments.manage')) {
            throw new AuthorizationException('Only finance (payments.manage) can verify commission deposits.');
        }
    }

    private function appendNote(?string $existing, ?string $note): ?string
    {
        $note = trim((string) $note);

        if ($note === '') {
            return $existing;
        }

        return trim(($existing ? $existing."\n" : '').'[finance] '.$note);
    }

    private function notifyCompany(?CommissionStatement $statement, BaseNotification $notification): void
    {
        $users = $statement?->company?->users;

        if ($users && $users->isNotEmpty()) {
            Notification::send($users, $notification->afterCommit());
        }
    }

    /** @param array<string, mixed> $properties */
    private function log(CommissionDeposit $deposit, string $event, User $by, array $properties = []): void
    {
        activity('commission_collection')
            ->performedOn($deposit)
            ->causedBy($by)
            ->event('deposit_'.$event)
            ->withProperties($properties + ['deposit_id' => $deposit->getKey(), 'statement_id' => $deposit->commission_statement_id])
            ->log("Commission deposit #{$deposit->getKey()} {$event}");
    }
}
