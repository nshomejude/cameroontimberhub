<?php

use App\Enums\OrganisationType;
use App\Models\CarbonProject;
use App\Models\Company;
use App\Support\CarbonDirectory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Cache::forget(CarbonDirectory::CACHE_KEY);
});

it('hides the Carbon Projects nav/footer links while carbon is dormant and the directory is empty', function () {
    config(['timber.signup.carbon_enabled' => false]);

    $this->get('/')->assertOk()->assertDontSee(route('carbon-projects').'"', false);
});

it('shows the Carbon Projects links once a project is listed', function () {
    config(['timber.signup.carbon_enabled' => false]);
    $company = Company::factory()->publiclyVisible()->create(['type' => OrganisationType::CarbonDeveloper]);
    CarbonProject::factory()->active()->for($company)->create();

    $this->get('/')->assertOk()->assertSee(route('carbon-projects').'"', false);
});

it('shows the Carbon Projects links when carbon signup is enabled', function () {
    config(['timber.signup.carbon_enabled' => true]);

    $this->get('/')->assertOk()->assertSee(route('carbon-projects').'"', false);
});

it('omits carbon_developer from the company-fields x-show list only while carbon is off', function () {
    config(['timber.signup.carbon_enabled' => false]);
    $off = $this->get(route('register'))->assertOk()->getContent();
    preg_match('/<div x-show="([^"]*)\.includes\(accountType\)"/', $off, $m);
    expect($m[1] ?? '')->toContain('logistics_partner')->not->toContain('carbon_developer');

    config(['timber.signup.carbon_enabled' => true]);
    $on = $this->get(route('register'))->assertOk()->getContent();
    preg_match('/<div x-show="([^"]*)\.includes\(accountType\)"/', $on, $m);
    expect($m[1] ?? '')->toContain('carbon_developer');
});

it('translates the carbon coming-soon strings', function (string $key) {
    foreach (['en', 'fr'] as $locale) {
        expect(__($key, [], $locale))->not->toBe($key);
    }
    expect(__($key, [], 'fr'))->not->toBe(__($key, [], 'en'));
})->with(['messages.register.coming_soon', 'messages.register.carbon_coming_soon']);
