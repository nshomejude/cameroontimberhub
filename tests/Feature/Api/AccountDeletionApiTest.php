<?php

use App\Actions\Account\DeleteAccount;
use App\Enums\CompanyStatus;
use App\Enums\ProductStatus;
use App\Features\DemoLoginsEnabled;
use App\Models\Company;
use App\Models\DeviceToken;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function deletableUser(array $attrs = []): User
{
    return User::factory()->create(array_merge(['password' => Hash::make('secret-pass-123'), 'phone' => '+237600000000'], $attrs));
}

it('anonymises the account, revokes tokens and device tokens, and logs it', function () {
    $user = deletableUser();
    $user->createToken('other-device');
    $token = $user->createToken('mobile')->plainTextToken;
    DeviceToken::create(['user_id' => $user->id, 'expo_push_token' => 'ExponentPushToken-x', 'platform' => 'ios']);

    $this->withToken($token)->deleteJson('/api/v1/auth/me', ['password' => 'secret-pass-123'])->assertNoContent();

    $fresh = $user->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->name)->toBe(DeleteAccount::DELETED_NAME)
        ->and($fresh->email)->toBe("deleted+{$user->id}@deleted.invalid")
        ->and($fresh->phone)->toBeNull()
        ->and(Hash::check('secret-pass-123', $fresh->password))->toBeFalse()
        ->and($fresh->tokens()->count())->toBe(0)
        ->and(DeviceToken::where('user_id', $user->id)->count())->toBe(0)
        ->and(Activity::where('description', 'account_deleted')->where('subject_id', $user->id)->exists())->toBeTrue();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('requires the current password', function () {
    $user = deletableUser();

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/auth/me', [])->assertUnprocessable();
    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/auth/me', ['password' => 'wrong'])
        ->assertUnprocessable()->assertJsonValidationErrors('password', 'error.details');

    expect($user->fresh()->name)->not->toBe(DeleteAccount::DELETED_NAME);
});

it('refuses staff self-deletion', function () {
    $user = deletableUser();
    $user->assignRole('admin');

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/auth/me', ['password' => 'secret-pass-123'])
        ->assertForbidden()->assertJsonPath('error.code', 'staff_cannot_self_delete');
});

it('refuses the sole owner of a company that has other members', function () {
    $user = deletableUser();
    $company = Company::factory()->create();
    $company->users()->attach($user, ['role' => 'owner']);
    $company->users()->attach(User::factory()->create(), ['role' => 'member']);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/auth/me', ['password' => 'secret-pass-123'])
        ->assertStatus(409)->assertJsonPath('error.code', 'transfer_ownership_first');

    expect($company->users()->whereKey($user->id)->exists())->toBeTrue();
});

it('detaches a non-owner and leaves the company alone', function () {
    $user = deletableUser();
    $company = Company::factory()->create(['status' => CompanyStatus::Verified]);
    $company->users()->attach(User::factory()->create(), ['role' => 'owner']);
    $company->users()->attach($user, ['role' => 'member']);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/auth/me', ['password' => 'secret-pass-123'])->assertNoContent();

    expect($company->users()->whereKey($user->id)->exists())->toBeFalse()
        ->and($company->fresh()->status)->toBe(CompanyStatus::Verified);
});

it('archives a company and its products when the user was its only member', function () {
    $user = deletableUser();
    $company = Company::factory()->create(['status' => CompanyStatus::Verified]);
    $company->users()->attach($user, ['role' => 'owner']);
    $product = Product::factory()->active()->create(['company_id' => $company->id]);

    $this->actingAs($user, 'sanctum')->deleteJson('/api/v1/auth/me', ['password' => 'secret-pass-123'])->assertNoContent();

    expect($company->fresh()->status)->toBe(CompanyStatus::Archived)
        ->and($product->fresh()->status)->toBe(ProductStatus::Archived)
        ->and($user->fresh()->companies()->count())->toBe(0);
});

it('deletes the account through the web confirmation page', function () {
    $user = deletableUser();

    $this->actingAs($user)->get(route('account.delete'))->assertOk()->assertSee('Delete my account');

    $this->actingAs($user)->post(route('account.destroy'), ['password' => 'secret-pass-123', 'confirm' => '1'])
        ->assertRedirect(route('home'));

    expect($user->fresh()->name)->toBe(DeleteAccount::DELETED_NAME);
    $this->assertGuest();
});

it('rejects web deletion without confirmation or with a wrong password', function () {
    $user = deletableUser();

    $this->actingAs($user)->post(route('account.destroy'), ['password' => 'secret-pass-123'])->assertSessionHasErrors('confirm');
    $this->actingAs($user)->post(route('account.destroy'), ['password' => 'nope', 'confirm' => '1'])->assertSessionHasErrors('password');

    expect($user->fresh()->name)->not->toBe(DeleteAccount::DELETED_NAME);
});

it('keeps demo persona and login endpoints closed when demo logins are disabled in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['demo.enabled' => false]);
    Feature::purge(DemoLoginsEnabled::class);

    $this->getJson('/api/v1/auth/demo-personas')->assertOk()->assertExactJson(['data' => []]);
    $this->postJson('/api/v1/auth/demo-login/buyer')->assertForbidden();
});
