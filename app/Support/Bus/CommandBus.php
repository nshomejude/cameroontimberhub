<?php

namespace App\Support\Bus;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Thin CQRS command dispatcher (blueprint: docs/superpowers/plans/2026-09-09-api-first-ddd-cqrs-event-driven.md, Task 0.1).
 *
 * Handler resolution convention (chosen and fixed — follow this for every
 * future command, do not invent a second convention):
 *
 *   Command class name with its trailing "Command" stripped (if present)
 *   plus a "Handler" suffix, in the SAME namespace as the command.
 *
 *   e.g. App\Domain\Trade\Commands\AwardQuoteCommand
 *     -> App\Domain\Trade\Commands\AwardQuoteHandler
 *
 * The handler is resolved via the Laravel container, so its constructor may
 * type-hint any dependency (existing Services included) for normal DI. The
 * whole handle() call is wrapped in DB::transaction() — a handler that throws
 * rolls back everything it did, including anything it wrote before the
 * exception.
 */
class CommandBus
{
    public function __construct(private readonly Container $container) {}

    public function dispatch(Command $command): mixed
    {
        $handlerClass = static::handlerClassFor($command);

        if (! class_exists($handlerClass)) {
            throw new RuntimeException(
                "No command handler found for ".$command::class.". Expected {$handlerClass}."
            );
        }

        /** @var HandlesCommand $handler */
        $handler = $this->container->make($handlerClass);

        if (! $handler instanceof HandlesCommand) {
            throw new RuntimeException("{$handlerClass} must implement ".HandlesCommand::class);
        }

        return DB::transaction(fn () => $handler->handle($command));
    }

    public static function handlerClassFor(Command $command): string
    {
        $class = $command::class;

        if (str_ends_with($class, 'Command')) {
            $class = substr($class, 0, -strlen('Command'));
        }

        return $class.'Handler';
    }
}
