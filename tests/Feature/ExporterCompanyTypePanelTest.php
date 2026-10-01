<?php

use App\Enums\OrganisationType;
use App\Enums\RfqType;
use App\Filament\Exporter\Resources\CompanySpecies\CompanySpeciesResource;
use App\Filament\Exporter\Resources\Leads\Pages\ListLeads;
use App\Filament\Exporter\Resources\Products\ProductResource;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Rfq;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
});

function panelUserFor(Company $company): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

it('hides Products and CompanySpecies for logistics and carbon-developer companies only', function (OrganisationType $type, bool $visible) {
    $this->actingAs(panelUserFor(Company::factory()->create(['type' => $type])));

    expect(ProductResource::canViewAny())->toBe($visible)
        ->and(CompanySpeciesResource::canViewAny())->toBe($visible);
})->with([
    'logistics' => [OrganisationType::Logistics, false],
    'carbon developer' => [OrganisationType::CarbonDeveloper, false],
    'supplier' => [OrganisationType::Supplier, true],
    'manufacturer' => [OrganisationType::Manufacturer, true],
]);

it('shows the RFQ type on exporter leads and filters by it', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $this->actingAs(panelUserFor($company));

    $transportRfq = Rfq::factory()->approved()->create(['type' => RfqType::Transport]);
    $exportRfq = Rfq::factory()->approved()->create(['type' => RfqType::Export]);
    $transport = Lead::create(['company_id' => $company->id, 'rfq_id' => $transportRfq->id, 'source' => 'rfq', 'status' => 'new', 'buyer_name' => 'Tom']);
    $export = Lead::create(['company_id' => $company->id, 'rfq_id' => $exportRfq->id, 'source' => 'rfq', 'status' => 'new', 'buyer_name' => 'Eve']);

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$transport, $export])
        ->assertSee('Transport')
        ->filterTable('rfq_type', RfqType::Transport->value)
        ->assertCanSeeTableRecords([$transport])
        ->assertCanNotSeeTableRecords([$export]);
});
