<?php

namespace App\Enums;

/**
 * Lifecycle of a Transformation Request (a requester company asking a
 * verified processor/manufacturer to perform a transformation service).
 *
 * Legal transitions (enforced by TransformationRequestService, mirroring
 * OrderService::TRANSITIONS):
 *
 *   Pending    -> Quoted, Accepted, Declined, Cancelled
 *   Quoted     -> Accepted, Declined, Cancelled
 *   Accepted   -> InProgress, Cancelled
 *   InProgress -> Completed
 *   Completed, Declined, Cancelled -> (terminal)
 *
 * `Accepted` is reached either directly from `Pending` (provider accepts
 * with no quote step) or from `Quoted` (requester accepts the provider's
 * quote) — both mean "both sides have agreed to do the job", which is why
 * `startJob` only ever moves off `Accepted`.
 */
enum TransformationRequestStatus: string
{
    case Pending = 'pending';
    case Quoted = 'quoted';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return __('messages.enums.transformation_request_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Quoted => 'warning',
            self::Accepted => 'primary',
            self::InProgress => 'warning',
            self::Completed => 'success',
            self::Declined, self::Cancelled => 'danger',
        };
    }

    /** True once the request can no longer change. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Declined, self::Cancelled], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
