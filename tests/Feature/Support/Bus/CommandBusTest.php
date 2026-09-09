<?php

/* ------------------------------------------------------------------ fixtures */
/* Namespaced under App\Domain\Trade\Commands purely so class_exists() finds a
   real, autoloadable "Handler" sibling next to the fixture command — the
   fixture never touches real Trade behaviour. */

namespace App\Domain\Trade\Commands {
    final class FakeBusCommand implements \App\Support\Bus\Command
    {
        public function __construct(public bool $shouldFail = false) {}
    }

    final class FakeBusHandler implements \App\Support\Bus\HandlesCommand
    {
        public function handle(\App\Support\Bus\Command $command): mixed
        {
            /** @var FakeBusCommand $command */
            activity('test')->withProperties(['marker' => 'command-bus-write'])->log('write');

            if ($command->shouldFail) {
                throw new \RuntimeException('forced handler failure');
            }

            return 'handled';
        }
    }
}

namespace {

    use App\Domain\Trade\Commands\FakeBusCommand;
    use App\Support\Bus\Command;
    use App\Support\Bus\CommandBus;
    use Spatie\Activitylog\Models\Activity;

    it('resolves and calls the matching handler via the container', function () {
        $result = app(CommandBus::class)->dispatch(new FakeBusCommand());

        expect($result)->toBe('handled');
        expect(Activity::where('description', 'write')->exists())->toBeTrue();
    });

    it('wraps the handler call in a transaction and rolls back a partial write on failure', function () {
        $before = Activity::count();

        expect(fn () => app(CommandBus::class)->dispatch(new FakeBusCommand(shouldFail: true)))
            ->toThrow(RuntimeException::class, 'forced handler failure');

        // The activity log row the handler wrote before throwing must not
        // have survived — the whole handle() call is one DB transaction.
        expect(Activity::count())->toBe($before);
    });

    it('throws a clear error when no handler class exists for the command', function () {
        $command = new class implements Command
        {
            //
        };

        expect(fn () => app(CommandBus::class)->dispatch($command))
            ->toThrow(RuntimeException::class, 'No command handler found');
    });
}
