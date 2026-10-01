<?php

/*
 * Admin RFQ triage table: Close works on New RFQs, illegal transitions are a
 * danger toast (not a 500), and Approve reports the real auto-routed count.
 */

use App\Enums\RfqStatus;
use App\Filament\Resources\Rfqs\Pages\ListRfqs;
use App\Filament\Resources\Rfqs\Tables\RfqsTable;
use App\Models\Rfq;
use App\Models\User;
use App\Services\RfqTriageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Notifications\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

function triageRfq(RfqStatus $status = RfqStatus::New): Rfq
{
    return Rfq::factory()->create(['status' => $status, 'email_verified_at' => now()]);
}

it('closes a new RFQ from the table', function () {
    $rfq = triageRfq();

    Livewire::test(ListRfqs::class)->callTableAction('close', $rfq)->assertNotified('RFQ closed');

    expect($rfq->refresh()->status)->toBe(RfqStatus::Closed);
});

it('turns an illegal transition into a danger notification instead of an exception', function () {
    $rfq = triageRfq(RfqStatus::Closed);

    // RfqsTable::run is the wrapper every triage action goes through.
    $run = new ReflectionMethod(RfqsTable::class, 'run');
    $run->invoke(null, fn () => app(RfqTriageService::class)->close($rfq, auth()->user()), 'RFQ closed');

    Notification::assertNotified(Notification::make()->title('Illegal RFQ transition closed -> closed')->danger());
    expect($rfq->refresh()->status)->toBe(RfqStatus::Closed);
});

it('reports the actual auto-routed count when approving', function () {
    $rfq = triageRfq();

    Livewire::test(ListRfqs::class)->callTableAction('approve', $rfq)
        ->assertNotified('RFQ approved — no supplier matched automatically; route it manually');

    expect($rfq->refresh()->status)->toBe(RfqStatus::Approved);
});
