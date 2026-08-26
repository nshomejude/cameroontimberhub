<?php

use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Models\User;
use App\Services\InquiryTriageService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeInquiryFor(Company $company, array $attributes = []): CompanyInquiry
{
    return CompanyInquiry::create(array_merge([
        'company_id' => $company->id,
        'name' => 'Bob Buyer',
        'email' => 'bob@acme.test',
        'message' => 'Interested in your sawn timber for export to Europe.',
        'status' => 'new',
    ], $attributes));
}

it('shows the inquiry queue only to inquiries.review holders', function () {
    $company = Company::factory()->create();
    makeInquiryFor($company, ['name' => 'Visible Buyer']);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get('/admin/inquiries')
        ->assertOk()
        ->assertSee('Visible Buyer');

    $nobody = User::factory()->create();
    $this->actingAs($nobody)->get('/admin/inquiries')->assertForbidden();
});

it('lets an admin run the inquiry triage actions end to end', function () {
    $company = Company::factory()->create();
    $inquiry = makeInquiryFor($company);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $triage = app(InquiryTriageService::class);
    $triage->startReview($inquiry, $admin);
    $triage->approve($inquiry->fresh(), $admin);
    $triage->close($inquiry->fresh(), $admin);

    expect($inquiry->fresh()->status->value)->toBe('closed');
});
