<?php

use App\Filament\Resources\Claims\Pages\CreateClaim;
use App\Models\Claim;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function claimsStaff(string $role = 'admin'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// --- Species::normalizeSupplierInput() -------------------------------------

it('matches a supplier-entered name via common_name', function () {
    $species = Species::factory()->create(['common_name' => 'Sapelli']);

    expect(Species::normalizeSupplierInput(' sapelli ')?->id)->toBe($species->id);
});

it('matches a supplier-entered name via scientific_name', function () {
    $species = Species::factory()->create(['scientific_name' => 'Entandrophragma cylindricum']);

    expect(Species::normalizeSupplierInput('ENTANDROPHRAGMA CYLINDRICUM')?->id)->toBe($species->id);
});

it('matches a supplier-entered name via french_name', function () {
    $species = Species::factory()->create(['french_name' => 'Sipo']);

    expect(Species::normalizeSupplierInput('sipo')?->id)->toBe($species->id);
});

it('matches a supplier-entered name via an entry in synonyms', function () {
    $species = Species::factory()->create(['synonyms' => ['Aboudikro', 'Tiama']]);

    expect(Species::normalizeSupplierInput('tiama')?->id)->toBe($species->id);
});

it('returns null for a genuinely unmatched name', function () {
    Species::factory()->create(['common_name' => 'Sapelli']);

    expect(Species::normalizeSupplierInput('Definitely Not A Real Species'))->toBeNull();
});

// --- New species fields ------------------------------------------------

it('saves and loads the new species governance fields', function () {
    $species = Species::factory()->create([
        'synonyms' => ['Alt Name'],
        'family' => 'Fabaceae',
        'commercial_categories' => ['hardwood', 'decorative'],
        'country_presence' => ['CM', 'GA'],
        'last_reviewed_at' => '2026-01-15',
    ]);

    $fresh = $species->fresh();

    expect($fresh->synonyms)->toBe(['Alt Name'])
        ->and($fresh->family)->toBe('Fabaceae')
        ->and($fresh->commercial_categories)->toBe(['hardwood', 'decorative'])
        ->and($fresh->country_presence)->toBe(['CM', 'GA'])
        ->and($fresh->last_reviewed_at->toDateString())->toBe('2026-01-15');
});

// --- Claim::scopeNeedsAttention() ---------------------------------------

it('needsAttention includes an overdue-review approved claim', function () {
    $claim = Claim::query()->create([
        'claim_text' => 'Overdue claim',
        'status' => 'approved',
        'review_date' => Carbon::today()->subDay(),
    ]);

    expect(Claim::query()->needsAttention()->pluck('id'))->toContain($claim->id);
});

it('needsAttention includes a needs_review-status claim', function () {
    $claim = Claim::query()->create([
        'claim_text' => 'Flagged claim',
        'status' => 'needs_review',
    ]);

    expect(Claim::query()->needsAttention()->pluck('id'))->toContain($claim->id);
});

it('needsAttention excludes a healthy approved claim with a future review date', function () {
    $claim = Claim::query()->create([
        'claim_text' => 'Healthy claim',
        'status' => 'approved',
        'review_date' => Carbon::today()->addMonths(3),
    ]);

    expect(Claim::query()->needsAttention()->pluck('id'))->not->toContain($claim->id);
});

// --- Claims admin resource ------------------------------------------------

it('renders the claims resource pages for an admin', function () {
    $claim = Claim::query()->create(['claim_text' => 'A claim', 'status' => 'draft']);

    $this->actingAs(claimsStaff());

    $this->get('/admin/claims')->assertOk();
    $this->get('/admin/claims/create')->assertOk();
    $this->get('/admin/claims/'.$claim->id.'/edit')->assertOk();
});

it('enforces required fields when creating a claim', function () {
    $this->actingAs(claimsStaff());

    Livewire::test(CreateClaim::class)
        ->fillForm(['claim_text' => '', 'status' => 'draft'])
        ->call('create')
        ->assertHasFormErrors(['claim_text' => 'required']);
});
