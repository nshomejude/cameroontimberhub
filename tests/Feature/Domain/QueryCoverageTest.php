<?php

use App\Domain\Catalog\Queries\ListSupplierProductsQuery;
use App\Domain\Catalog\Queries\SearchProductCatalogueQuery;
use App\Domain\Commerce\Queries\GetCompanySubscriptionQuery;
use App\Domain\Identity\Queries\ListPendingVerificationsQuery;
use App\Domain\Identity\Queries\ListVerifiedSuppliersQuery;
use App\Enums\SubscriptionStatus;
use App\Enums\VerificationRequestStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\ProductCatalogueService;
use App\Services\SearchService;
use App\Support\Bus\QueryBus;

/*
 * Gap 3 (docs/architecture/GAPS.md): the new named Query classes over the
 * Catalog / Identity / Commerce reads. Each handler is a thin seam and must
 * return exactly what the caller produced inline before.
 */

function bus(): QueryBus
{
    return app(QueryBus::class);
}

it('SearchProductCatalogueQuery returns the same page as ProductCatalogueService::search()', function () {
    $company = Company::factory()->publiclyVisible()->create();
    Product::factory()->count(3)->active()->for($company, 'company')->create();
    Product::factory()->draft()->for($company, 'company')->create();

    $filters = ['q' => '', 'types' => [], 'speciesIn' => [], 'region' => '', 'supplier' => '', 'certifiedOnly' => false, 'bestSellers' => false, 'sort' => 'featured'];

    $viaService = app(ProductCatalogueService::class)->search($filters, 12);
    $viaQuery = bus()->dispatch(new SearchProductCatalogueQuery($filters, 12));

    expect($viaQuery->pluck('id')->sort()->values()->all())
        ->toBe($viaService->pluck('id')->sort()->values()->all())
        ->and($viaQuery->total())->toBe(3);
});

it('ListSupplierProductsQuery returns only the acting user\'s company products', function () {
    $own = Company::factory()->publiclyVisible()->create();
    $other = Company::factory()->publiclyVisible()->create();
    $mine = Product::factory()->for($own, 'company')->create();
    Product::factory()->for($other, 'company')->create();

    $user = User::factory()->create();
    $user->companies()->attach($own, ['role' => 'owner', 'is_primary' => true]);

    $builder = bus()->dispatch(new ListSupplierProductsQuery($user->getKey()));

    expect($builder->pluck('id')->all())->toBe([$mine->id]);
});

it('ListSupplierProductsQuery yields nothing for a null user', function () {
    Product::factory()->for(Company::factory()->publiclyVisible()->create(), 'company')->create();

    expect(bus()->dispatch(new ListSupplierProductsQuery(null))->count())->toBe(0);
});

it('ListVerifiedSuppliersQuery matches SearchService::companyQuery()->paginate()', function () {
    Company::factory()->publiclyVisible()->count(2)->create();
    Company::factory()->create(['legal_name' => 'Hidden Co']); // not publicly visible

    $filters = ['q' => '', 'types' => [], 'speciesIn' => [], 'region' => '', 'sort' => 'featured'];

    $viaService = app(SearchService::class)->companyQuery($filters)->paginate(12);
    $viaQuery = bus()->dispatch(new ListVerifiedSuppliersQuery($filters, 12));

    expect($viaQuery->pluck('id')->sort()->values()->all())
        ->toBe($viaService->pluck('id')->sort()->values()->all())
        ->and($viaQuery->total())->toBe(2);
});

it('ListPendingVerificationsQuery returns only open requests, newest first, with eager loads', function () {
    $open = VerificationRequest::factory()->create(['status' => VerificationRequestStatus::Pending]);
    VerificationRequest::factory()->create(['status' => VerificationRequestStatus::Approved]);

    $rows = bus()->dispatch(new ListPendingVerificationsQuery())->get();

    expect($rows->pluck('id')->all())->toBe([$open->id])
        ->and($rows->first()->relationLoaded('company'))->toBeTrue()
        ->and($rows->first()->relationLoaded('assignedTo'))->toBeTrue();
});

it('GetCompanySubscriptionQuery returns the latest active subscription like the page did', function () {
    $company = Company::factory()->create();
    Subscription::factory()->create(['company_id' => $company->getKey(), 'status' => SubscriptionStatus::Cancelled]);
    $active = Subscription::factory()->create(['company_id' => $company->getKey(), 'status' => SubscriptionStatus::Active]);

    $viaQuery = bus()->dispatch(new GetCompanySubscriptionQuery($company->getKey()));
    $inline = $company->subscriptions()->active()->latest()->first();

    expect($viaQuery?->getKey())->toBe($active->getKey())
        ->and($viaQuery?->getKey())->toBe($inline?->getKey());
});

it('GetCompanySubscriptionQuery returns null when the company has no active subscription', function () {
    $company = Company::factory()->create();

    expect(bus()->dispatch(new GetCompanySubscriptionQuery($company->getKey())))->toBeNull();
});
