<?php

namespace App\Enums;

/**
 * Whether a species may be exported as unprocessed logs.
 *
 * IMPORTANT: Cameroon's log-export rules are set by policy (MINFOF / finance
 * law schedules) and change over time. Values stored on a species record are
 * INFORMATIONAL PLACEHOLDERS unless explicitly verified against the current
 * MINFOF publications. `Unknown` is the default and must be used whenever the
 * status has not been verified. Never present this field as legal advice.
 */
enum LogExportStatus: string
{
    case Permitted = 'permitted';
    case Restricted = 'restricted';
    case Banned = 'banned';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Permitted => 'Permitted',
            self::Restricted => 'Restricted',
            self::Banned => 'Banned',
            self::Unknown => 'Unknown / not verified',
        };
    }

    /** Filament badge color key. */
    public function color(): string
    {
        return match ($this) {
            self::Permitted => 'success',
            self::Restricted => 'warning',
            self::Banned => 'danger',
            self::Unknown => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
