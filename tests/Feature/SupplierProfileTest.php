<?php

use App\Enums\BadgeStatus;
use App\Enums\BadgeType;
use App\Enums\CompanyStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentVisibility;
use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\Species;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

/** Pull the JSON-LD graphs the layout renders into the page head. */
function supplierProfileGraphs(string $html): Collection
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return collect($m[1])->map(fn (string $json) => json_decode($json, true))->filter();
}

function profileSupplier(array $attributes = []): Company
{
    return Company::factory()->publiclyVisible()->create($attributes);
}

// ---- Visibility boundary -------------------------------------------------

it('renders the profile for a publicly visible company', function () {
    $company = profileSupplier(['legal_name' => 'Profile Legal Co', 'trade_name' => 'ProfileCo']);

    $this->get(route('companies.show', $company->slug))
        ->assertOk()
        ->assertSee('ProfileCo')
        ->assertSee('Verified Supplier')
        ->assertSee('Verified profile')
        ->assertSee('Documents reviewed by Cameroon Timber Hub based on information submitted by the company.');
});

it('404s for a company that is not publicly visible', function (CompanyStatus $status) {
    $company = profileSupplier();
    $company->update(['status' => $status]);

    $this->get(route('companies.show', $company->slug))->assertNotFound();
})->with([
    'draft' => [CompanyStatus::Draft],
    'pending' => [CompanyStatus::Pending],
    'suspended' => [CompanyStatus::Suspended],
    'rejected' => [CompanyStatus::Rejected],
    'archived' => [CompanyStatus::Archived],
]);

it('404s for a verified company whose last badge has expired', function () {
    $company = profileSupplier();
    $company->verificationBadges()->update(['status' => BadgeStatus::Revoked->value]);

    $this->get(route('companies.show', $company->slug))->assertNotFound();
});

// ---- Products ------------------------------------------------------------

it('lists only this supplier\'s active products', function () {
    $company = profileSupplier();
    $other = profileSupplier();
    $species = Species::factory()->create();

    Product::factory()->for($company)->for($species)->create(['name' => 'Mine Active Beam', 'status' => ProductStatus::Active]);
    Product::factory()->for($company)->for($species)->create(['name' => 'Mine Draft Beam', 'status' => ProductStatus::Draft]);
    Product::factory()->for($other)->for($species)->create(['name' => 'Rival Active Beam', 'status' => ProductStatus::Active]);

    $this->get(route('companies.show', $company->slug))
        ->assertOk()
        ->assertSee('Mine Active Beam')
        ->assertDontSee('Mine Draft Beam')
        ->assertDontSee('Rival Active Beam');
});

it('links "view all products" into the marketplace filtered by this supplier', function () {
    $company = profileSupplier();
    Product::factory()->for($company)->for(Species::factory())->create(['status' => ProductStatus::Active]);

    $this->get(route('companies.show', $company->slug))
        ->assertOk()
        ->assertSee(route('marketplace', ['supplier' => $company->slug]), false);
});

it('filters the marketplace by supplier slug', function () {
    $company = profileSupplier();
    $other = profileSupplier();
    $species = Species::factory()->create();

    Product::factory()->for($company)->for($species)->create(['name' => 'Kept Listing', 'status' => ProductStatus::Active]);
    Product::factory()->for($other)->for($species)->create(['name' => 'Dropped Listing', 'status' => ProductStatus::Active]);

    $this->get(route('marketplace', ['supplier' => $company->slug]))
        ->assertOk()
        ->assertSee('Kept Listing')
        ->assertDontSee('Dropped Listing');
});

it('omits the products tab entirely when the supplier has no active listing', function () {
    $company = profileSupplier();

    $this->get(route('companies.show', $company->slug))
        ->assertOk()
        ->assertDontSee('id="panel-products"', false);
});

// ---- Null metrics are hidden, never zeroed -------------------------------

it('hides rating and every zero/null stat rather than rendering an empty one', function () {
    $company = profileSupplier([
        'rating_avg' => null,
        'rating_count' => null,
        'orders_completed' => null,
        'response_rate_percent' => null,
        'response_time_hours' => null,
        'on_time_delivery_percent' => null,
        'employee_count' => null,
        'year_founded' => null,
        'annual_capacity_m3' => null,
    ]);

    $html = $this->get(route('companies.show', $company->slug))->assertOk()->getContent();

    expect($html)
        ->not->toContain('0 reviews')
        ->not->toContain('Overall rating')
        ->not->toContain('Total reviews')
        ->not->toContain('Orders completed')
        ->not->toContain('Rated 0')
        // A null percentage metric now reads as "Not enough history yet",
        // never a fabricated "0%".
        ->not->toContain('0%')
        ->toContain('Not enough history yet');
});

it('renders the rating only when real rating data exists', function () {
    $company = profileSupplier(['rating_avg' => 4.8, 'rating_count' => 32]);

    $this->get(route('companies.show', $company->slug))
        ->assertOk()
        ->assertSee('4.8')
        ->assertSee('(32 reviews)');
});

// ---- Certifications ------------------------------------------------------

it('shows only real active badges as certifications', function () {
    $company = profileSupplier();

    $company->verificationBadges()->create([
        'badge_type' => BadgeType::SigifRegistered,
        'status' => BadgeStatus::Active,
        'issued_at' => now(),
        'is_public' => true,
        'reference_code' => 'REAL-BADGE-1',
    ]);

    $company->verificationBadges()->create([
        'badge_type' => BadgeType::CitesApproved,
        'status' => BadgeStatus::Revoked,
        'issued_at' => now(),
        'is_public' => true,
        'reference_code' => 'REVOKED-BADGE-1',
    ]);

    $html = $this->get(route('companies.show', $company->slug))->assertOk()->getContent();

    expect($html)
        ->toContain('SIGIF Registered')
        ->not->toContain('REVOKED-BADGE-1')
        ->not->toContain('CITES Approved')
        // No invented certification-scheme claims on the profile itself.
        ->not->toContain('PEFC')
        ->not->toContain('FSC-C');
});

// ---- Contact / WhatsApp --------------------------------------------------

it('renders a well-formed WhatsApp button only for a public contact', function () {
    $company = profileSupplier();
    $company->contacts()->update(['is_public' => true, 'whatsapp' => '+237 6 99 00 11 22']);
    $company->refresh();

    $this->get(route('companies.show', $company->slug))
        ->assertOk()
        ->assertSee('https://wa.me/237699001122', false)
        ->assertSee('Chat with Supplier');
});

it('never renders a chat button when no public contact publishes a number', function () {
    $company = profileSupplier();
    $company->contacts()->update(['is_public' => false, 'phone' => null, 'whatsapp' => null]);

    $html = $this->get(route('companies.show', $company->slug))->assertOk()->getContent();

    expect($html)
        ->not->toContain('wa.me')
        ->not->toContain('Chat with Supplier');
});

// ---- Tabs / server-side rendering ---------------------------------------

it('renders every tab panel server-side', function () {
    $company = profileSupplier();

    $html = $this->get(route('companies.show', $company->slug))->assertOk()->getContent();

    expect($html)
        ->toContain('role="tablist"')
        ->toContain('aria-selected="true"')
        ->toContain('id="panel-overview"')
        ->toContain('id="panel-contact"')
        ->toContain('role="tabpanel"');
});

// ---- Documents -----------------------------------------------------------

it('never leaks private or unapproved documents', function () {
    $company = profileSupplier();
    $type = DocumentType::create(['key' => 'export_licence_test', 'name' => 'Export Licence']);

    $base = [
        'company_id' => $company->id,
        'document_type_id' => $type->id,
        'storage_path' => 'documents/x.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
    ];

    CompanyDocument::create($base + [
        'original_filename' => 'SECRET-PRIVATE.pdf',
        'visibility' => DocumentVisibility::Private,
        'status' => DocumentStatus::Approved,
    ]);

    CompanyDocument::create($base + [
        'original_filename' => 'SECRET-PENDING.pdf',
        'visibility' => DocumentVisibility::Public,
        'status' => DocumentStatus::Pending,
    ]);

    CompanyDocument::create($base + [
        'original_filename' => 'SECRET-BUYER-ONLY.pdf',
        'visibility' => DocumentVisibility::BuyerVisible,
        'status' => DocumentStatus::Approved,
    ]);

    $html = $this->get(route('companies.show', $company->slug))->assertOk()->getContent();

    expect($html)
        ->not->toContain('SECRET-PRIVATE')
        ->not->toContain('SECRET-PENDING')
        ->not->toContain('SECRET-BUYER-ONLY')
        ->not->toContain('documents/x.pdf');
});

// ---- Structured data -----------------------------------------------------

it('emits valid Organization and BreadcrumbList JSON-LD', function () {
    $company = profileSupplier(['rating_avg' => null, 'rating_count' => null]);

    $graphs = supplierProfileGraphs($this->get(route('companies.show', $company->slug))->assertOk()->getContent());

    $org = $graphs->firstWhere('@type', 'Organization');
    expect($org)->not->toBeNull()
        ->and($org['name'])->toBe($company->name)
        ->and($org['url'])->toBe(route('companies.show', $company->slug))
        ->and($org)->not->toHaveKey('aggregateRating');

    $crumbs = $graphs->firstWhere('@type', 'BreadcrumbList');
    expect($crumbs['itemListElement'])->toHaveCount(3)
        ->and($crumbs['itemListElement'][2]['name'])->toBe($company->name);
});

it('emits aggregateRating only when real rating data exists', function () {
    $company = profileSupplier(['rating_avg' => 4.6, 'rating_count' => 19]);

    $org = supplierProfileGraphs($this->get(route('companies.show', $company->slug))->getContent())
        ->firstWhere('@type', 'Organization');

    expect($org['aggregateRating']['@type'])->toBe('AggregateRating')
        ->and($org['aggregateRating']['reviewCount'])->toBe(19);
});

it('puts real social links into sameAs and never invents them', function () {
    $company = profileSupplier(['website_url' => null]);
    $company->socialLinks()->create(['platform' => 'linkedin', 'url' => 'https://linkedin.com/company/demo-supplier']);

    $org = supplierProfileGraphs($this->get(route('companies.show', $company->slug))->getContent())
        ->firstWhere('@type', 'Organization');

    expect($org['sameAs'])->toBe(['https://linkedin.com/company/demo-supplier']);

    $bare = profileSupplier(['website_url' => null]);
    $bareOrg = supplierProfileGraphs($this->get(route('companies.show', $bare->slug))->getContent())
        ->firstWhere('@type', 'Organization');

    expect($bareOrg)->not->toHaveKey('sameAs');
});
