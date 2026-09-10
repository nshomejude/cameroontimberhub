<?php

use App\Models\Receipt;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    RateLimiter::clear('receipt-verify-ip:127.0.0.1');
    RateLimiter::clear('receipt-verify-ip-hour:127.0.0.1');
});

it('chains each receipt to the previous one', function () {
    $a = Receipt::factory()->create();
    $b = Receipt::factory()->create();

    expect($a->prev_hash)->toBeNull()
        ->and($a->hash)->not->toBeNull()
        ->and($b->prev_hash)->toBe($a->hash)
        ->and($b->hash)->not->toBe($a->hash)
        ->and($a->fresh()->verifiesIntegrity())->toBeTrue()
        ->and($b->fresh()->verifiesIntegrity())->toBeTrue();
});

it('detects tampering via the verify command', function () {
    $receipts = Receipt::factory()->count(3)->create();
    $this->artisan('receipts:verify-chain')->assertExitCode(0);

    DB::table('receipts')->where('id', $receipts[1]->id)->update(['amount' => 999999]);

    $this->artisan('receipts:verify-chain')->assertExitCode(1);
});

it('leaves payload columns immutable after issue', function () {
    $receipt = Receipt::factory()->create();

    try {
        $receipt->update(['amount' => 1]);
        $this->fail('Expected a RuntimeException when mutating a payload column.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('immutable');
    }

    $receipt = $receipt->fresh();
    expect($receipt->amount)->toBe('1250.00');

    // Mutable operational state is still writable.
    $receipt->update(['voided_at' => now(), 'void_reason' => 'issued in error']);
    expect($receipt->fresh()->verifiesIntegrity())->toBeTrue();
});

it('backfills an idempotent chain', function () {
    $receipts = Receipt::factory()->count(3)->create();

    // Wipe the chain as if these rows predate it.
    DB::table('receipts')->update(['hash' => null, 'prev_hash' => null]);

    $this->artisan('receipts:backfill-integrity-chain')->assertExitCode(0);
    $firstPass = Receipt::orderBy('issued_at')->orderBy('id')->pluck('hash', 'id')->toArray();

    $this->artisan('receipts:backfill-integrity-chain')->assertExitCode(0);
    $secondPass = Receipt::orderBy('issued_at')->orderBy('id')->pluck('hash', 'id')->toArray();

    expect($secondPass)->toBe($firstPass)
        ->and(array_values($firstPass)[0])->not->toBeNull();
    $this->artisan('receipts:verify-chain')->assertExitCode(0);
});

it('surfaces the integrity state on the public verification page', function () {
    $receipt = Receipt::factory()->create();

    $this->followingRedirects()
        ->get($receipt->verificationUrl())
        ->assertOk()
        ->assertSee('Integrity verified');
});
