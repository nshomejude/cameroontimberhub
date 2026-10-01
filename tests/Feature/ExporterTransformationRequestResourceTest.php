<?php

use App\Enums\TransformationRequestStatus;
use App\Filament\Exporter\Resources\LotTransformations\LotTransformationResource;
use App\Filament\Exporter\Resources\LotTransformations\Pages\ListLotTransformations;
use App\Filament\Exporter\Resources\TransformationRequests\Pages\ListTransformationRequests;
use App\Filament\Exporter\Resources\TransformationRequests\Pages\ViewTransformationRequest;
use App\Filament\Exporter\Resources\TransformationRequests\TransformationRequestResource;
use App\Models\Company;
use App\Models\TransformationRequest;
use App\Models\User;
use App\Notifications\TransformationRequestCreatedNotification;
use App\Services\TransformationRequestService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
    Notification::fake();
});

function etrCompany(?string $type): array
{
    $company = Company::factory()->verified()->create(['type' => $type]);
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => 'owner']);

    return [$user, $company];
}

function etrRequest(User $requester, Company $provider): TransformationRequest
{
    return app(TransformationRequestService::class)->create($requester, [
        'provider_slug' => $provider->slug,
        'service' => 'sawing',
        'volume_m3' => 10,
    ]);
}

it('shows the provider its received requests by default and hides others', function () {
    [$requester] = etrCompany('buyer');
    [$providerUser, $provider] = etrCompany('artisan');
    [, $otherProvider] = etrCompany('processor');

    $mine = etrRequest($requester, $provider);
    $notMine = etrRequest($requester, $otherProvider);

    $this->actingAs($providerUser);

    expect(TransformationRequestResource::canViewAny())->toBeTrue();

    Livewire::test(ListTransformationRequests::class)
        ->assertSet('activeTab', 'received')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$notMine]);
});

it('shows the requester its sent requests on the sent tab', function () {
    [$requester] = etrCompany('buyer');
    [, $provider] = etrCompany('processor');
    $request = etrRequest($requester, $provider);

    $this->actingAs($requester);

    Livewire::test(ListTransformationRequests::class)
        ->assertSet('activeTab', 'sent')
        ->assertCanSeeTableRecords([$request]);
});

it('hides the resource from a non-provider company with no requests', function () {
    [$user] = etrCompany('buyer');
    $this->actingAs($user);

    expect(TransformationRequestResource::canViewAny())->toBeFalse();
});

it('drives the provider pipeline through the service from the view page', function () {
    [$requester] = etrCompany('buyer');
    [$providerUser, $provider] = etrCompany('manufacturer');
    $request = etrRequest($requester, $provider);

    $this->actingAs($providerUser);

    Livewire::test(ViewTransformationRequest::class, ['record' => $request->reference_code])
        ->assertActionVisible('accept')
        ->assertActionVisible('quote')
        ->assertActionHidden('startJob')
        ->assertActionHidden('acceptQuote')
        ->callAction('quote', ['amount' => 1500, 'currency' => 'XAF', 'notes' => 'ok'])
        ->assertHasNoActionErrors();

    expect($request->refresh()->status)->toBe(TransformationRequestStatus::Quoted)
        ->and((float) $request->quote_amount)->toBe(1500.0);

    // Requester accepts the quote, then the provider starts and completes.
    app(TransformationRequestService::class)->acceptQuote($requester, $request);

    Livewire::test(ViewTransformationRequest::class, ['record' => $request->reference_code])
        ->callAction('startJob');
    expect($request->refresh()->status)->toBe(TransformationRequestStatus::InProgress);

    Livewire::test(ViewTransformationRequest::class, ['record' => $request->reference_code])
        ->callAction('completeJob', ['output_volume_m3' => 8]);
    expect($request->refresh()->status)->toBe(TransformationRequestStatus::Completed)
        ->and($request->lot_transformation_id)->not->toBeNull();

    // The processor sees the ledger row in its read-only lot transformations list.
    Livewire::test(ListLotTransformations::class)
        ->assertCanSeeTableRecords([$request->lotTransformation]);
    expect(LotTransformationResource::canCreate())->toBeFalse();
});

it('declines with a reason from a table row', function () {
    [$requester] = etrCompany('buyer');
    [$providerUser, $provider] = etrCompany('processor');
    $request = etrRequest($requester, $provider);

    $this->actingAs($providerUser);

    Livewire::test(ListTransformationRequests::class)
        ->callTableAction('decline', $request, ['reason' => 'Fully booked']);

    expect($request->refresh()->status)->toBe(TransformationRequestStatus::Declined)
        ->and($request->decline_reason)->toBe('Fully booked');
});

it('404s a request the company is not party to', function () {
    [$requester] = etrCompany('buyer');
    [, $provider] = etrCompany('processor');
    [$outsider] = etrCompany('processor');
    $request = etrRequest($requester, $provider);

    $this->actingAs($outsider)
        ->get(TransformationRequestResource::getUrl('view', ['record' => $request], panel: 'exporter'))
        ->assertNotFound();
});

it('links the created notification to the exporter resource', function () {
    [$requester] = etrCompany('buyer');
    [$providerUser, $provider] = etrCompany('artisan');
    $request = etrRequest($requester, $provider);

    $data = (new TransformationRequestCreatedNotification($request))->toArray($providerUser);

    expect($data['url'])->toBe(TransformationRequestResource::getUrl('view', ['record' => $request], panel: 'exporter'))
        ->and($data['url'])->toContain('/dashboard/');
});
