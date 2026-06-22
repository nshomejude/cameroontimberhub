<?php

namespace App\Services;

final readonly class CompletenessResult
{
    /** @param list<string> $missing human-readable unmet requirements */
    public function __construct(
        public int $percentage,
        public array $missing,
    ) {}

    public function isComplete(): bool
    {
        return $this->missing === [];
    }
}
