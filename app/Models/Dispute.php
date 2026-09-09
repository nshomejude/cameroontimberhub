<?php

namespace App\Models;

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A formal dispute case opened against an Order (blueprint §64).
 *
 * Lifecycle: Opened -> Evidence Submission -> Counterparty Response ->
 * Admin Decision -> optional Appeal -> Closed. Every transition method below
 * validates the CURRENT status allows it (RuntimeException otherwise) and,
 * except for the admin-only actions (resolve/close), that the acting user is
 * actually a party to the dispute's order.
 *
 * Party identity is never trusted from these rows alone: isParty() re-derives
 * membership from the order's own relationships (order.user_id for the buyer,
 * order.company_id's members for the supplier), matching the philosophy in
 * Order's own docblock -- money, names and parties come from the real
 * relations, never from a value that could have been tampered with upstream.
 */
class Dispute extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => DisputeCategory::class,
            'status' => DisputeStatus::class,
            'resolved_at' => 'datetime',
            'appealed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function raisedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_user_id');
    }

    public function raisedByCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'raised_by_company_id');
    }

    public function respondentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_user_id');
    }

    public function respondentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'respondent_company_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(DisputeEvidence::class)->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class)->orderBy('id');
    }

    /* ------------------------------------------------------------- party */

    /**
     * True only when $user is genuinely a party to this dispute's order:
     * the buyer who placed it, or a member of the supplier company that
     * fulfilled it. Re-derived from the order every time -- never trusts
     * the raised_by/respondent columns alone, which exist for record-keeping.
     */
    public function isParty(User $user): bool
    {
        $order = $this->relationLoaded('order') ? $this->order : $this->order()->first();

        if ($order === null) {
            return false;
        }

        if ((int) $order->user_id === (int) $user->getKey()) {
            return true;
        }

        return $order->company_id !== null
            && $user->companies()->whereKey($order->company_id)->exists();
    }

    /* -------------------------------------------------------- transitions */

    /**
     * Submit evidence for this dispute. Allowed while the case is still
     * gathering evidence (Opened or already EvidencePending). Moves the case
     * into CounterpartyResponsePending once submitted, prompting the other
     * side to reply.
     */
    public function submitEvidence(User $actor): void
    {
        $this->assertParty($actor);

        if (! in_array($this->status, [DisputeStatus::Opened, DisputeStatus::EvidencePending], true)) {
            throw new RuntimeException('Evidence can only be submitted while the dispute is open or awaiting evidence.');
        }

        $this->update(['status' => DisputeStatus::CounterpartyResponsePending]);
    }

    /**
     * The counterparty replies (via a DisputeMessage, created by the caller
     * before or after this call). Moves the case to UnderReview once a
     * response has been logged, so an admin can make a decision.
     */
    public function respondentReply(User $actor): void
    {
        $this->assertParty($actor);

        if ($this->status !== DisputeStatus::CounterpartyResponsePending) {
            throw new RuntimeException('A response can only be recorded while the dispute is awaiting counterparty response.');
        }

        $this->update(['status' => DisputeStatus::UnderReview]);
    }

    /**
     * Either party can push a stalled case straight to admin review without
     * waiting on the other side.
     */
    public function escalateToReview(User $actor): void
    {
        $this->assertParty($actor);

        if (! in_array($this->status, [
            DisputeStatus::Opened,
            DisputeStatus::EvidencePending,
            DisputeStatus::CounterpartyResponsePending,
        ], true)) {
            throw new RuntimeException('This dispute cannot be escalated to review from its current status.');
        }

        $this->update(['status' => DisputeStatus::UnderReview]);
    }

    /** Admin-only: record the decision. Only valid once the case is under review. */
    public function resolve(User $admin, string $notes): void
    {
        if ($this->status !== DisputeStatus::UnderReview) {
            throw new RuntimeException('A dispute can only be resolved once it is under review.');
        }

        $notes = trim($notes);

        if ($notes === '') {
            throw new RuntimeException('Resolution notes are required.');
        }

        $this->update([
            'status' => DisputeStatus::Resolved,
            'resolution_notes' => $notes,
            'resolved_by' => $admin->getKey(),
            'resolved_at' => now(),
        ]);
    }

    /** Either party may appeal a resolved decision. */
    public function appeal(User $actor): void
    {
        $this->assertParty($actor);

        if ($this->status !== DisputeStatus::Resolved) {
            throw new RuntimeException('Only a resolved dispute can be appealed.');
        }

        $this->update([
            'status' => DisputeStatus::Appealed,
            'appealed_at' => now(),
        ]);
    }

    /** Admin-only: close the case out, whether resolved or appealed and re-decided. */
    public function close(User $admin): void
    {
        if (! in_array($this->status, [DisputeStatus::Resolved, DisputeStatus::Appealed], true)) {
            throw new RuntimeException('A dispute can only be closed once it has been resolved.');
        }

        $this->update([
            'status' => DisputeStatus::Closed,
            'closed_at' => now(),
        ]);
    }

    private function assertParty(User $actor): void
    {
        if (! $this->isParty($actor)) {
            throw new RuntimeException('You are not a party to this dispute.');
        }
    }
}
