<?php

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Production-readiness plan Task A4 — queue & schedule reliability.
 *
 * Every queued Job and every queued Listener must declare a failed() handler so
 * a permanent failure lands on the `errors` channel rather than vanishing into
 * failed_jobs; and `ops:queue-health` must make failed-job growth and
 * pending-job starvation loud without ever gating.
 */

it('every queued job and listener declares a failed() handler', function () {
    $classes = collect()
        ->merge(discoverClasses(app_path('Jobs'), 'App\\Jobs'))
        ->merge(discoverClasses(app_path('Listeners'), 'App\\Listeners'))
        ->filter(fn (string $fqcn): bool => is_subclass_of($fqcn, ShouldQueue::class)
            || in_array(ShouldQueue::class, class_implements($fqcn) ?: [], true));

    expect($classes)->not->toBeEmpty();

    $missing = $classes->reject(fn (string $fqcn): bool => method_exists($fqcn, 'failed'));

    expect($missing->all())->toBe([], 'queued classes without a failed() handler: '.$missing->implode(', '));
});

it('ops:queue-health warns on the errors channel when failed_jobs grows', function () {
    Log::shouldReceive('channel')->with('errors')->andReturnSelf();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'failed_jobs count grew'));
    Log::shouldReceive('info')->zeroOrMoreTimes();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SomeJob']),
        'exception' => "RuntimeException: boom\n#0 ...",
        'failed_at' => now(),
    ]);

    $this->artisan('ops:queue-health')->assertExitCode(0);
});

it('ops:queue-health stays quiet when failed_jobs is empty and no job is starving', function () {
    Log::shouldReceive('channel')->with('errors')->andReturnSelf();
    Log::shouldReceive('warning')->never();
    Log::shouldReceive('info')->zeroOrMoreTimes();

    $this->artisan('ops:queue-health')->assertExitCode(0);
});

it('ops:queue-health warns when the oldest pending job exceeds the starvation threshold', function () {
    Log::shouldReceive('channel')->with('errors')->andReturnSelf();
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'starvation threshold'));
    Log::shouldReceive('info')->zeroOrMoreTimes();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\StuckJob']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subMinutes(20)->timestamp,
        'created_at' => now()->subMinutes(20)->timestamp,
    ]);

    $this->artisan('ops:queue-health')->assertExitCode(0);
});

/** Recursively resolve PHP class FQCNs under a directory. */
function discoverClasses(string $dir, string $namespace): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace([$dir.DIRECTORY_SEPARATOR, '/', '.php'], ['', '\\', ''], $file->getPathname());
        $fqcn = $namespace.'\\'.$relative;
        if (class_exists($fqcn)) {
            $out[] = $fqcn;
        }
    }

    return $out;
}
