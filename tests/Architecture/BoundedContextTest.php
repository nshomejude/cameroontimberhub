<?php

/**
 * Closes GAPS.md gap 2 — automated bounded-context boundary enforcement.
 *
 * The 8-context ownership map in docs/architecture/README.md §1 is convention
 * only. These assertions make two of its rules structural:
 *
 *  1. A context's `App\Domain\{Context}` code may not import another context's
 *     `App\Domain\{Other}` namespace. Cross-context communication goes through
 *     a domain event or an application-service call, never a direct reach into
 *     another context's behavioural namespace.
 *
 *     NOTE: per the "Models stay in app/Models/ — deliberately" strangler-fig
 *     decision in README.md, shared `App\Models\*` access is CURRENTLY ALLOWED
 *     and is deliberately NOT forbidden here. Only cross-`Domain` imports are.
 *
 *  2. CQRS / event naming + contract conventions (README.md §2):
 *       - `App\Domain\*\Commands\*Command`  implements App\Support\Bus\Command
 *       - `App\Domain\*\Commands\*Handler`  implements App\Support\Bus\HandlesCommand
 *       - `App\Domain\*\Queries\*Query`     implements App\Support\Bus\Query
 *       - `App\Domain\*\Queries\*Handler`   implements App\Support\Bus\HandlesQuery
 *       - `App\Domain\*\Events\*`           implements App\Support\Events\DomainEvent
 *
 * As of the closing commit NO real cross-context violation exists — every
 * `use App\Domain\...` statement under app/Domain/ resolves within its own
 * context. If one is ever introduced legitimately, add it as a documented
 * exception in the ->ignoring(...) list below with a TODO explaining why.
 */

use App\Support\Bus\Command as CommandContract;
use App\Support\Bus\HandlesCommand;
use App\Support\Bus\HandlesQuery;
use App\Support\Bus\Query as QueryContract;
use App\Support\Events\DomainEvent;

$contexts = ['Trade', 'Logistics', 'Compliance', 'Identity', 'Commerce', 'Catalog'];

/*
|--------------------------------------------------------------------------
| 1. Cross-context boundary — no Domain\{X} may use Domain\{Y}
|--------------------------------------------------------------------------
*/
foreach ($contexts as $context) {
    $others = array_map(
        fn (string $c) => "App\\Domain\\{$c}",
        array_values(array_filter($contexts, fn ($c) => $c !== $context)),
    );

    arch("{$context} does not depend on another bounded context's Domain namespace")
        ->expect("App\\Domain\\{$context}")
        ->not->toUse($others);
        // Documented temporary exceptions go here as ->ignoring('App\Domain\...')
        // with a `// TODO: {$context} should not import X — <why it does today>`.
        // None needed as of gap-2 closure.
}

/*
|--------------------------------------------------------------------------
| 2. CQRS / event contract + naming conventions
|--------------------------------------------------------------------------
| Pest's arch()->toImplement applies to every class in a namespace, so the
| mixed Commands/ + Queries/ folders (DTO *and* Handler) are checked by
| reflection over the file layout instead.
*/
test('domain CQRS and event classes honour their Support contracts', function () use ($contexts) {
    $domainPath = app_path('Domain');

    $rules = [
        ['Commands', '/Command$/', CommandContract::class],
        ['Commands', '/Handler$/', HandlesCommand::class],
        ['Queries',  '/Query$/',   QueryContract::class],
        ['Queries',  '/Handler$/', HandlesQuery::class],
        ['Events',   '/./',        DomainEvent::class],
    ];

    $checked = 0;

    foreach ($contexts as $context) {
        foreach ($rules as [$folder, $pattern, $contract]) {
            $dir = "{$domainPath}/{$context}/{$folder}";
            if (! is_dir($dir)) {
                continue;
            }

            foreach (glob("{$dir}/*.php") as $file) {
                $class = basename($file, '.php');
                if (! preg_match($pattern, $class)) {
                    continue;
                }

                $fqcn = "App\\Domain\\{$context}\\{$folder}\\{$class}";
                expect(class_exists($fqcn))->toBeTrue("{$fqcn} could not be autoloaded");
                expect(in_array($contract, class_implements($fqcn), true))
                    ->toBeTrue("{$fqcn} must implement {$contract}");
                $checked++;
            }
        }
    }

    expect($checked)->toBeGreaterThan(0, 'no domain CQRS/event classes were discovered');
});
