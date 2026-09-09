<?php

namespace App\Domain\Trade\Queries {
    final class FakeBusQuery implements \App\Support\Bus\Query
    {
        public function __construct(public int $value = 0) {}
    }

    final class FakeBusHandler implements \App\Support\Bus\HandlesQuery
    {
        public function handle(\App\Support\Bus\Query $query): mixed
        {
            /** @var FakeBusQuery $query */
            return $query->value * 2;
        }
    }
}

namespace {

    use App\Domain\Trade\Queries\FakeBusQuery;
    use App\Support\Bus\Query;
    use App\Support\Bus\QueryBus;

    it('resolves and calls the matching query handler via the container', function () {
        $result = app(QueryBus::class)->dispatch(new FakeBusQuery(value: 21));

        expect($result)->toBe(42);
    });

    it('throws a clear error when no handler class exists for the query', function () {
        $query = new class implements Query
        {
            //
        };

        expect(fn () => app(QueryBus::class)->dispatch($query))
            ->toThrow(RuntimeException::class, 'No query handler found');
    });
}
