<?php

namespace App\Enums;

enum AiProvider: string
{
    case Claude = 'claude';

    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude (Anthropic)',
        };
    }

    /** Default model id used when no override is set on the ai_settings row. */
    public function defaultModel(): string
    {
        return match ($this) {
            self::Claude => 'claude-sonnet-5',
        };
    }
}
