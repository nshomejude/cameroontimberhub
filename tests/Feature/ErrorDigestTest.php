<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

/**
 * Production-readiness plan Task A2: the dedicated `errors` log channel and the
 * `ops:error-digest` command that summarises it.
 */
beforeEach(function () {
    $this->logDir = storage_path('logs/testing-error-digest');
    File::ensureDirectoryExists($this->logDir);
    File::cleanDirectory($this->logDir);

    config(['logging.channels.errors.path' => $this->logDir.'/errors.log']);
    Log::forgetChannel('errors');

    $this->todayFile = $this->logDir.'/errors-'.now()->format('Y-m-d').'.log';
});

afterEach(function () {
    File::deleteDirectory($this->logDir);
});

function writeErrorLine(string $file, string $level, string $message): void
{
    File::append($file, '['.now()->format('Y-m-d H:i:s').'] testing.'.strtoupper($level).': '.$message."\n");
}

it('emails a digest with the total count and a top offender', function () {
    Mail::fake();
    config(['mail.ops_address' => 'ops@example.test']);

    foreach (range(1, 3) as $i) {
        writeErrorLine($this->todayFile, 'error', "Call to a member function foo() on null in User.php:{$i}");
    }
    writeErrorLine($this->todayFile, 'error', 'Something else entirely happened');

    $this->artisan('ops:error-digest')->assertSuccessful();

    Mail::assertSentCount(1);
    Mail::assertSent(\App\Mail\ErrorDigestMail::class, function ($mail) {
        $rendered = $mail->render();

        return str_contains($rendered, 'Total errors: 4')
            && str_contains($rendered, '[3x]')
            && str_contains($rendered, 'Call to a member function foo() on null');
    });
});

it('does not send or throw when the log is empty', function () {
    Mail::fake();
    config(['mail.ops_address' => 'ops@example.test']);

    $this->artisan('ops:error-digest')->assertSuccessful();

    Mail::assertNothingSent();
});

it('does not send or throw when the ops address is unset', function () {
    Mail::fake();
    config(['mail.ops_address' => null]);

    writeErrorLine($this->todayFile, 'error', 'A real error that would otherwise be reported');

    $this->artisan('ops:error-digest')->assertSuccessful();

    Mail::assertNothingSent();
});

it('normalises ids and numbers so like errors group together', function () {
    Mail::fake();
    config(['mail.ops_address' => 'ops@example.test']);

    writeErrorLine($this->todayFile, 'error', 'Order 4821 not found for user 91');
    writeErrorLine($this->todayFile, 'error', 'Order 7 not found for user 3320');

    $this->artisan('ops:error-digest')->assertSuccessful();

    Mail::assertSent(\App\Mail\ErrorDigestMail::class, function ($mail) {
        return str_contains($mail->render(), '[2x] Order <n> not found for user <n>');
    });
});

it('writes an unhandled route exception into the errors channel', function () {
    Route::get('/__test/error-digest-boom', function () {
        throw new \RuntimeException('kaboom from a route');
    });

    $this->get('/__test/error-digest-boom')->assertStatus(500);

    expect(File::exists($this->todayFile))->toBeTrue();
    expect(File::get($this->todayFile))
        ->toContain('RuntimeException')
        ->toContain('kaboom from a route');
});
