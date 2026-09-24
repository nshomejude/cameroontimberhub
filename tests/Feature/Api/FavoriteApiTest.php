<?php

use App\Models\Company;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;

/* ------------------------------------------------------------- POST /favorites */

it('favorites a product', function () {
    $user = User::factory()->create();
    $product = Product::factory()->active()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/favorites', ['type' => 'product', 'id' => $product->id])
        ->assertOk()
        ->assertJson(['favorited' => true]);

    expect(Favorite::query()
        ->where('user_id', $user->id)
        ->where('favoritable_type', Product::class)
        ->where('favoritable_id', $product->id)
        ->exists())->toBeTrue();
});

it('favorites a company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/favorites', ['type' => 'company', 'id' => $company->id])
        ->assertOk()
        ->assertJson(['favorited' => true]);

    expect(Favorite::query()
        ->where('user_id', $user->id)
        ->where('favoritable_type', Company::class)
        ->where('favoritable_id', $company->id)
        ->exists())->toBeTrue();
});

it('is idempotent when favoriting the same item twice', function () {
    $user = User::factory()->create();
    $product = Product::factory()->active()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/favorites', ['type' => 'product', 'id' => $product->id])->assertOk();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/favorites', ['type' => 'product', 'id' => $product->id])->assertOk();

    expect(Favorite::query()->where('user_id', $user->id)->where('favoritable_type', Product::class)->where('favoritable_id', $product->id)->count())->toBe(1);
});

it('404s for a nonexistent target id', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/favorites', ['type' => 'product', 'id' => 999999])
        ->assertNotFound();
});

it('422s for an invalid type', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/favorites', ['type' => 'bogus', 'id' => 1])
        ->assertUnprocessable();
});

it('refuses an unauthenticated favorite', function () {
    $product = Product::factory()->active()->create();

    $this->postJson('/api/v1/favorites', ['type' => 'product', 'id' => $product->id])
        ->assertUnauthorized();
});

/* ----------------------------------------------------------- DELETE /favorites */

it('unfavorites a product', function () {
    $user = User::factory()->create();
    $product = Product::factory()->active()->create();
    Favorite::query()->create(['user_id' => $user->id, 'favoritable_type' => Product::class, 'favoritable_id' => $product->id]);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/favorites', ['type' => 'product', 'id' => $product->id])
        ->assertOk()
        ->assertJson(['favorited' => false]);

    expect(Favorite::query()->where('user_id', $user->id)->where('favoritable_type', Product::class)->where('favoritable_id', $product->id)->exists())->toBeFalse();
});

it('unfavoriting something never favorited does not error', function () {
    $user = User::factory()->create();
    $product = Product::factory()->active()->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/favorites', ['type' => 'product', 'id' => $product->id])
        ->assertOk()
        ->assertJson(['favorited' => false]);
});

/* -------------------------------------------------------------- GET /favorites */

it('lists only the caller own favorited products via the real ProductResource shape', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $product = Product::factory()->active()->create(['name' => 'My Favorite Timber']);
    $company = Company::factory()->create();

    Favorite::query()->create(['user_id' => $user->id, 'favoritable_type' => Product::class, 'favoritable_id' => $product->id]);
    Favorite::query()->create(['user_id' => $user->id, 'favoritable_type' => Company::class, 'favoritable_id' => $company->id]);
    Favorite::query()->create(['user_id' => $otherUser->id, 'favoritable_type' => Product::class, 'favoritable_id' => $product->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/favorites?type=product')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('My Favorite Timber')
        ->and($response->json('data.0.is_favorited'))->toBeTrue();
});

it('filters favorites by type=company using the real SupplierResource shape', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['legal_name' => 'My Favorite Supplier SARL']);

    Favorite::query()->create(['user_id' => $user->id, 'favoritable_type' => Company::class, 'favoritable_id' => $company->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/favorites?type=company')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($company->id)
        ->and($response->json('data.0.is_favorited'))->toBeTrue();
});

/* --------------------------------------------------- is_favorited on resources */

it('renders is_favorited=false for a guest viewing a product', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->active()->for($company)->create();

    $this->getJson('/api/v1/products/'.$product->slug)
        ->assertOk()
        ->assertJsonPath('data.is_favorited', false);
});

it('renders is_favorited=true for an authenticated user who favorited the product', function () {
    $user = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->active()->for($company)->create();
    Favorite::query()->create(['user_id' => $user->id, 'favoritable_type' => Product::class, 'favoritable_id' => $product->id]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/products/'.$product->slug)
        ->assertOk()
        ->assertJsonPath('data.is_favorited', true);
});

it('renders is_favorited=false on SupplierResource for a non-favoriting authenticated user', function () {
    $user = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/suppliers/'.$company->slug)
        ->assertOk()
        ->assertJsonPath('data.is_favorited', false);
});
