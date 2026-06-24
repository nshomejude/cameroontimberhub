<?php

namespace App\Actions\Rfq;

use App\Models\Rfq;
use App\Services\IntakeService;

class IntakeRfq
{
    public function __construct(private readonly IntakeService $intake) {}

    /**
     * @param  array<string, mixed>  $header   Buyer identity and shipping fields
     * @param  array<int, array<string, mixed>>  $items  One or more line items
     */
    public function execute(array $header, array $items, ?string $source = null): Rfq
    {
        return $this->intake->createRfq($header, $items, $source);
    }
}
