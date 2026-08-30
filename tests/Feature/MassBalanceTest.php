<?php

use App\Models\Company;
use App\Models\LotTransformation;
use App\Models\TimberLot;
use App\Services\MassBalanceService;

it('computes loss and ratio from input/output line items, not caller-supplied totals', function () {
    $processor = Company::factory()->create();
    $logs = TimberLot::factory()->create();
    $sawn = TimberLot::factory()->create();

    $transformation = LotTransformation::recordFor(
        processorCompanyId: $processor->id,
        transformationType: 'sawing',
        inputs: [
            ['lot' => $logs, 'quantity' => 100],
        ],
        outputs: [
            ['lot' => $sawn, 'quantity' => 72],
        ],
    );

    expect((float) $transformation->input_volume_m3)->toBe(100.0)
        ->and((float) $transformation->output_volume_m3)->toBe(72.0)
        ->and((float) $transformation->loss_volume_m3)->toBe(28.0)
        ->and((float) $transformation->transformation_ratio)->toBe(0.72);
});

it('sums multiple input lots into input_volume_m3', function () {
    $processor = Company::factory()->create();
    $logA = TimberLot::factory()->create();
    $logB = TimberLot::factory()->create();
    $sawn = TimberLot::factory()->create();

    $transformation = LotTransformation::recordFor(
        processorCompanyId: $processor->id,
        transformationType: 'sawing',
        inputs: [
            ['lot' => $logA, 'quantity' => 60],
            ['lot' => $logB, 'quantity' => 40],
        ],
        outputs: [
            ['lot' => $sawn, 'quantity' => 72],
        ],
    );

    expect((float) $transformation->input_volume_m3)->toBe(100.0)
        ->and($transformation->inputLots)->toHaveCount(2);
});

it('walks a 2-hop chain from kiln-dried boards back to the original logs', function () {
    $processor = Company::factory()->create();
    $logs = TimberLot::factory()->create();
    $sawn = TimberLot::factory()->create();
    $dried = TimberLot::factory()->create();

    LotTransformation::recordFor(
        processorCompanyId: $processor->id,
        transformationType: 'sawing',
        inputs: [['lot' => $logs, 'quantity' => 100]],
        outputs: [['lot' => $sawn, 'quantity' => 72]],
    );

    LotTransformation::recordFor(
        processorCompanyId: $processor->id,
        transformationType: 'kiln_drying',
        inputs: [['lot' => $sawn, 'quantity' => 72]],
        outputs: [['lot' => $dried, 'quantity' => 58]],
    );

    $sources = (new MassBalanceService)->traceSources($dried);

    expect($sources->pluck('id')->sort()->values()->all())
        ->toBe(collect([$logs->id, $sawn->id])->sort()->values()->all());
});

it('does not infinite-loop on circular transformation data (depth limit kicks in)', function () {
    $processor = Company::factory()->create();
    $lotA = TimberLot::factory()->create();
    $lotB = TimberLot::factory()->create();

    // Contrive a circular chain: A -> B, then B -> A.
    LotTransformation::recordFor(
        processorCompanyId: $processor->id,
        transformationType: 'sawing',
        inputs: [['lot' => $lotA, 'quantity' => 50]],
        outputs: [['lot' => $lotB, 'quantity' => 40]],
    );

    LotTransformation::recordFor(
        processorCompanyId: $processor->id,
        transformationType: 'sawing',
        inputs: [['lot' => $lotB, 'quantity' => 40]],
        outputs: [['lot' => $lotA, 'quantity' => 30]],
    );

    $service = new MassBalanceService;

    $start = microtime(true);
    $sources = $service->traceSources($lotA);
    $elapsed = microtime(true) - $start;

    // The visited-guard/depth-limit must terminate the walk rather than
    // looping forever between A and B; exactly which lots end up in the
    // (nonsensical, circular) result matters less than that it terminates
    // quickly and stays bounded.
    expect($elapsed)->toBeLessThan(5.0)
        ->and($sources->count())->toBeLessThanOrEqual(2);
});

it('respects the hard depth limit on a long linear chain', function () {
    $processor = Company::factory()->create();
    $lots = TimberLot::factory()->count(MassBalanceService::MAX_DEPTH + 5)->create();

    for ($i = 0; $i < $lots->count() - 1; $i++) {
        LotTransformation::recordFor(
            processorCompanyId: $processor->id,
            transformationType: 'sawing',
            inputs: [['lot' => $lots[$i], 'quantity' => 10]],
            outputs: [['lot' => $lots[$i + 1], 'quantity' => 9]],
        );
    }

    $service = new MassBalanceService;
    $sources = $service->traceSources($lots->last());

    // A chain longer than MAX_DEPTH must be cut off, not walked in full.
    expect($sources->count())->toBeLessThan($lots->count() - 1);
});
