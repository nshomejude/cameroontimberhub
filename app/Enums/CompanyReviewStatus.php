<?php

namespace App\Enums;

/**
 * Moderation state of a supplier review.
 *
 * Only `Published` rows count towards `companies.rating_avg` / `rating_count`
 * and only they are rendered on a public profile — so taking a review down
 * genuinely removes it from the arithmetic rather than just hiding it.
 *
 * Mirrors the `company_reviews_status_check` CHECK constraint exactly.
 */
enum CompanyReviewStatus: string
{
    case Published = 'published';
    case Pending = 'pending';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Published',
            self::Pending => 'Awaiting moderation',
            self::Rejected => 'Removed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Published => 'success',
            self::Pending => 'warning',
            self::Rejected => 'danger',
        };
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
