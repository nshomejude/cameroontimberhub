<?php

namespace App\Support\Bus;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Thin CQRS query dispatcher — same handler-resolution convention as
 * CommandBus (see its doc block): Query class name with a trailing "Query"
 * stripped, plus "Handler", same namespace. NOT wrapped in a DB transaction —
 * reads don't need one.
 */
class QueryBus
{
    public function __construct(private readonly Container $container) {}

    public function dispatch(Query $query): mixed
    {
        $handlerClass = static::handlerClassFor($query);

        if (! class_exists($handlerClass)) {
            throw new RuntimeException(
                "No query handler found for ".$query::class.". Expected {$handlerClass}."
            );
        }

        /** @var HandlesQuery $handler */
        $handler = $this->container->make($handlerClass);

        if (! $handler instanceof HandlesQuery) {
            throw new RuntimeException("{$handlerClass} must implement ".HandlesQuery::class);
        }

        return $handler->handle($query);
    }

    public static function handlerClassFor(Query $query): string
    {
        $class = $query::class;

        if (str_ends_with($class, 'Query')) {
            $class = substr($class, 0, -strlen('Query'));
        }

        return $class.'Handler';
    }
}
