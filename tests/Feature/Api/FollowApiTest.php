<?php

use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Follow;
use App\Models\Product;
use App\Models\User;

/* ------------------------------------------------------------------ POST /follow */

it('follows a company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/follow', ['type' => 'company', 'id' => $company->id])
        ->assertOk()
        ->assertJson(['following' => true]);

    expect(Follow::query()->where('follower_id', $user->id)->where('followable_type', Company::class)->where('followable_id', $company->id)->exists())->toBeTrue();
});

it('follows another user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/follow', ['type' => 'user', 'id' => $other->id])
        ->assertOk()
        ->assertJson(['following' => true]);

    expect(Follow::query()->where('follower_id', $user->id)->where('followable_type', User::class)->where('followable_id', $other->id)->exists())->toBeTrue();
});

it('is idempotent when following the same company twice', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/follow', ['type' => 'company', 'id' => $company->id])->assertOk();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/follow', ['type' => 'company', 'id' => $company->id])->assertOk();

    expect(Follow::query()->where('follower_id', $user->id)->where('followable_type', Company::class)->where('followable_id', $company->id)->count())->toBe(1);
});

it('rejects following yourself with 422', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/follow', ['type' => 'user', 'id' => $user->id])
        ->assertUnprocessable();

    expect(Follow::query()->where('follower_id', $user->id)->exists())->toBeFalse();
});

it('404s for a nonexistent follow target', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/follow', ['type' => 'company', 'id' => 999999])
        ->assertNotFound();
});

it('422s for an invalid follow type', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/follow', ['type' => 'bogus', 'id' => 1])
        ->assertUnprocessable();
});

it('refuses an unauthenticated follow', function () {
    $company = Company::factory()->create();

    $this->postJson('/api/v1/follow', ['type' => 'company', 'id' => $company->id])
        ->assertUnauthorized();
});

/* --------------------------------------------------------------- DELETE /follow */

it('unfollows a company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Follow::query()->create(['follower_id' => $user->id, 'followable_type' => Company::class, 'followable_id' => $company->id]);

    $this->actingAs($user, 'sanctum')
        ->deleteJson('/api/v1/follow', ['type' => 'company', 'id' => $company->id])
        ->assertOk()
        ->assertJson(['following' => false]);

    expect(Follow::query()->where('follower_id', $user->id)->where('followable_type', Company::class)->where('followable_id', $company->id)->exists())->toBeFalse();
});

/* ----------------------------------------------------------- GET /following */

it('lists companies and users the caller follows with the correct shape', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['legal_name' => 'Followed Timber SARL']);
    $otherUser = User::factory()->create(['name' => 'Followed Person']);

    Follow::query()->create(['follower_id' => $user->id, 'followable_type' => Company::class, 'followable_id' => $company->id]);
    Follow::query()->create(['follower_id' => $user->id, 'followable_type' => User::class, 'followable_id' => $otherUser->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/following')
        ->assertOk();

    $rows = collect($response->json('data'));

    $companyRow = $rows->firstWhere('type', 'company');
    $userRow = $rows->firstWhere('type', 'user');

    expect($rows)->toHaveCount(2)
        ->and($companyRow['id'])->toBe($company->id)
        ->and($companyRow['slug'])->toBe($company->slug)
        ->and($companyRow)->toHaveKey('logo_url')
        ->and($userRow['id'])->toBe($otherUser->id)
        ->and($userRow['slug'])->toBeNull()
        ->and($userRow['avatar_url'])->toBeNull();
});

/* ----------------------------------------------------------- GET /followers */

it('lists who follows the caller, reversed direction from /following', function () {
    $user = User::factory()->create();
    $follower = User::factory()->create(['name' => 'A Fan']);

    Follow::query()->create(['follower_id' => $follower->id, 'followable_type' => User::class, 'followable_id' => $user->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/followers')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($follower->id)
        ->and($response->json('data.0.type'))->toBe('user');
});

it('does not include another user unrelated follow in /followers', function () {
    $user = User::factory()->create();
    $unrelatedFollower = User::factory()->create();
    $unrelatedTarget = User::factory()->create();

    Follow::query()->create(['follower_id' => $unrelatedFollower->id, 'followable_type' => User::class, 'followable_id' => $unrelatedTarget->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/followers')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(0);
});

/* --------------------------------------------------- is_following on SupplierResource */

it('renders is_following and followers_count on SupplierResource', function () {
    $user = User::factory()->create();
    $follower = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();

    Follow::query()->create(['follower_id' => $user->id, 'followable_type' => Company::class, 'followable_id' => $company->id]);
    Follow::query()->create(['follower_id' => $follower->id, 'followable_type' => Company::class, 'followable_id' => $company->id]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/suppliers/'.$company->slug)
        ->assertOk();

    expect($response->json('data.is_following'))->toBeTrue()
        ->and($response->json('data.followers_count'))->toBe(2);
});

it('renders is_following=false for a guest', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $this->getJson('/api/v1/suppliers/'.$company->slug)
        ->assertOk()
        ->assertJsonPath('data.is_following', false);
});

/* ------------------------------------------------------------------- GET /feed */

it('returns real published-product events from followed companies only', function () {
    $user = User::factory()->create();
    $followedCompany = Company::factory()->create(['legal_name' => 'Followed Supplier']);
    $unfollowedCompany = Company::factory()->create(['legal_name' => 'Not Followed Supplier']);

    Follow::query()->create(['follower_id' => $user->id, 'followable_type' => Company::class, 'followable_id' => $followedCompany->id]);

    $followedProduct = Product::factory()->for($followedCompany)->create(['name' => 'Feed Visible Product', 'status' => ProductStatus::Active]);
    Product::factory()->for($unfollowedCompany)->create(['name' => 'Feed Hidden Product', 'status' => ProductStatus::Active]);
    Product::factory()->for($followedCompany)->create(['name' => 'Draft Still Hidden', 'status' => ProductStatus::Draft]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/feed')
        ->assertOk();

    $items = collect($response->json('data'));

    expect($items)->toHaveCount(1)
        ->and($items->first()['id'])->toBe($followedProduct->id)
        ->and($items->first()['type'])->toBe('product')
        ->and($items->first()['company']['name'])->toBe('Followed Supplier');
});

it('returns an empty feed for a user following no one', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Product::factory()->for($company)->create(['status' => ProductStatus::Active]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/feed')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(0);
});

it('never pads the feed with invented announcement entries', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    Follow::query()->create(['follower_id' => $user->id, 'followable_type' => Company::class, 'followable_id' => $company->id]);

    Product::factory()->for($company)->create(['status' => ProductStatus::Active]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/feed')
        ->assertOk();

    $types = collect($response->json('data'))->pluck('type')->unique()->values()->all();

    expect($types)->toBe(['product'])
        ->and($types)->not->toContain('announcement');
});

it('refuses an unauthenticated feed request', function () {
    $this->getJson('/api/v1/feed')->assertUnauthorized();
});
