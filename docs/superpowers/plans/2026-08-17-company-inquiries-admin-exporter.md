# Company Inquiries — Admin Moderation + Exporter Lead Detail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give admins a moderation queue for `CompanyInquiry` records (mirroring the existing RFQ triage queue) and give exporters the actual buyer message/phone/RFQ items on their Lead detail page, which is currently missing.

**Architecture:** Follow the existing RFQ triage pattern exactly: a `InquiryTriageService` state machine (reusing the already-shared `RfqStatus` enum), a `CompanyInquiryPolicy`, a new `inquiries.review` permission, and a List-only Filament admin resource with `ActionGroup` triage actions. Separately, add a read-only "Buyer request" section to the exporter's existing `LeadForm`.

**Tech Stack:** Laravel 13, Filament 5, Pest, Spatie permission, Spatie activitylog, PostgreSQL.

Spec: `docs/superpowers/specs/2026-08-17-company-inquiries-admin-exporter-design.md`

---

### Task 1: `InquiryTriageService` state machine

**Files:**
- Create: `app/Services/InquiryTriageService.php`
- Test: `tests/Unit/InquiryTriageServiceTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\RfqStatus;
use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Models\User;
use App\Services\InquiryTriageService;
use Illuminate\Support\Facades\Config;
use Spatie\Activitylog\Models\Activity;

function makeInquiry(array $attributes = []): CompanyInquiry
{
    return CompanyInquiry::create(array_merge([
        'company_id' => Company::factory()->create()->id,
        'name' => 'Bob Buyer',
        'email' => 'bob@acme.test',
        'message' => 'Interested in your sawn timber for export to Europe.',
        'status' => 'new',
    ], $attributes));
}

it('allows the documented legal transitions', function () {
    $inquiry = makeInquiry();
    $admin = User::factory()->create();
    $service = app(InquiryTriageService::class);

    $service->startReview($inquiry, $admin);
    expect($inquiry->fresh()->status)->toBe(RfqStatus::InReview);

    $service->approve($inquiry->fresh(), $admin);
    expect($inquiry->fresh()->status)->toBe(RfqStatus::Approved);

    $service->close($inquiry->fresh(), $admin);
    expect($inquiry->fresh()->status)->toBe(RfqStatus::Closed);
});

it('rejects illegal transitions', function () {
    $inquiry = makeInquiry(['status' => 'closed']);
    $admin = User::factory()->create();
    $service = app(InquiryTriageService::class);

    $service->approve($inquiry, $admin);
})->throws(RuntimeException::class, 'Illegal inquiry transition closed -> approved');

it('marks spam and logs the reason on reject', function () {
    $inquiry = makeInquiry();
    $admin = User::factory()->create();
    $service = app(InquiryTriageService::class);

    $service->markSpam($inquiry, $admin);
    expect($inquiry->fresh()->status)->toBe(RfqStatus::Spam);

    $service->reject($inquiry->fresh(), 'Duplicate submission', $admin);
    expect($inquiry->fresh()->status)->toBe(RfqStatus::Rejected);

    $activity = Activity::where('log_name', 'inquiry')->latest('id')->first();
    expect($activity)->not->toBeNull()
        ->and($activity->properties['to'])->toBe('rejected')
        ->and($activity->properties['reason'])->toBe('Duplicate submission');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/InquiryTriageServiceTest.php`
Expected: FAIL — `Class "App\Services\InquiryTriageService" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Enums\RfqStatus;
use App\Models\CompanyInquiry;
use App\Models\User;
use RuntimeException;

/**
 * Admin inquiry triage state machine, mirroring RfqTriageService. Reuses
 * RfqStatus since CompanyInquiry::status already casts to it. No route()
 * method: an inquiry is already scoped to the one company the buyer
 * contacted, unlike an RFQ which fans out to multiple exporters.
 */
class InquiryTriageService
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'new' => ['in_review', 'approved', 'rejected', 'spam'],
        'in_review' => ['approved', 'rejected', 'spam', 'closed'],
        'approved' => ['closed', 'rejected'],
        'rejected' => ['closed'],
        'spam' => ['rejected', 'closed'],
        'closed' => [],
    ];

    public function transition(CompanyInquiry $inquiry, RfqStatus $to, User $actor, ?string $reason = null): void
    {
        if (! in_array($to->value, self::TRANSITIONS[$inquiry->status->value] ?? [], true)) {
            throw new RuntimeException("Illegal inquiry transition {$inquiry->status->value} -> {$to->value}");
        }

        $inquiry->update(['status' => $to]);

        activity('inquiry')->performedOn($inquiry)->causedBy($actor)->event('status_changed')
            ->withProperties(['to' => $to->value, 'reason' => $reason])->log("Inquiry status -> {$to->value}");
    }

    public function startReview(CompanyInquiry $inquiry, User $actor): void
    {
        $this->transition($inquiry, RfqStatus::InReview, $actor);
    }

    public function approve(CompanyInquiry $inquiry, User $actor): void
    {
        $this->transition($inquiry, RfqStatus::Approved, $actor);
    }

    public function reject(CompanyInquiry $inquiry, string $reason, User $actor): void
    {
        $this->transition($inquiry, RfqStatus::Rejected, $actor, $reason);
    }

    public function markSpam(CompanyInquiry $inquiry, User $actor): void
    {
        $this->transition($inquiry, RfqStatus::Spam, $actor);
    }

    public function close(CompanyInquiry $inquiry, User $actor): void
    {
        $this->transition($inquiry, RfqStatus::Closed, $actor);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/InquiryTriageServiceTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/InquiryTriageService.php tests/Unit/InquiryTriageServiceTest.php
git commit -m "Add InquiryTriageService state machine for admin inquiry moderation"
```

---

### Task 2: `inquiries.review` permission + `CompanyInquiryPolicy`

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Create: `app/Policies/CompanyInquiryPolicy.php`
- Test: `tests/Unit/CompanyInquiryPolicyTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\CompanyInquiry;
use App\Models\User;
use App\Policies\CompanyInquiryPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('grants viewAny/view only to users with inquiries.review', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $nobody = User::factory()->create();
    $policy = new CompanyInquiryPolicy;

    expect($policy->viewAny($admin))->toBeTrue()
        ->and($policy->viewAny($nobody))->toBeFalse();
});

it('super_admin has inquiries.review via the full permission set', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super_admin');

    expect($superAdmin->can('inquiries.review'))->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/CompanyInquiryPolicyTest.php`
Expected: FAIL — `Class "App\Policies\CompanyInquiryPolicy" not found`

- [ ] **Step 3: Add the permission to the seeder**

In `database/seeders/RolesAndPermissionsSeeder.php`, add `'inquiries.review'` to the `PERMISSIONS` array right after `'rfqs.route',`:

```php
    public const PERMISSIONS = [
        'companies.view',
        'companies.manage',
        'companies.suspend',
        'documents.review',
        'verification.review',
        'badges.issue',
        'badges.revoke',
        'species.manage',
        'rfqs.triage',
        'rfqs.route',
        'inquiries.review',
        'pages.manage',
        'plans.manage',
        'users.manage',
        'audit.view',
    ];
```

And add it to the `admin` role's list in `MATRIX` (next to `rfqs.triage`, `rfqs.route`):

```php
    public const MATRIX = [
        'admin' => [
            'companies.view', 'companies.manage',
            'documents.review', 'verification.review',
            'badges.issue', 'badges.revoke',
            'species.manage', 'rfqs.triage', 'rfqs.route', 'inquiries.review',
            'pages.manage', 'audit.view',
        ],
        'verification_officer' => [
            'companies.view', 'documents.review', 'verification.review',
            'badges.issue', 'badges.revoke', 'audit.view',
        ],
        'content_manager' => [
            'companies.view', 'species.manage', 'pages.manage',
        ],
    ];
```

- [ ] **Step 4: Write `CompanyInquiryPolicy`**

```php
<?php

namespace App\Policies;

use App\Models\CompanyInquiry;
use App\Models\User;

class CompanyInquiryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inquiries.review');
    }

    public function view(User $user, CompanyInquiry $inquiry): bool
    {
        return $user->can('inquiries.review');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/CompanyInquiryPolicyTest.php`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php app/Policies/CompanyInquiryPolicy.php tests/Unit/CompanyInquiryPolicyTest.php
git commit -m "Add inquiries.review permission and CompanyInquiryPolicy"
```

---

### Task 3: Admin `InquiryResource`

**Files:**
- Create: `app/Filament/Resources/Inquiries/InquiryResource.php`
- Create: `app/Filament/Resources/Inquiries/Pages/ListInquiries.php`
- Create: `app/Filament/Resources/Inquiries/Tables/InquiriesTable.php`
- Test: `tests/Feature/InquiryModerationTest.php`

- [ ] **Step 1: Write the failing tests**

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/InquiryModerationTest.php`
Expected: FAIL — 404 on `/admin/inquiries` (no such resource/route yet)

- [ ] **Step 3: Write `InquiriesTable`**

```php
<?php

namespace App\Filament\Resources\Inquiries\Tables;

use App\Enums\RfqStatus;
use App\Models\CompanyInquiry;
use App\Services\InquiryTriageService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InquiriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')->label('Company')->searchable(),
                TextColumn::make('name')->label('Buyer')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('message')->wrap()->limit(80),
                IconColumn::make('email_verified_at')->label('Verified')->boolean(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (RfqStatus $state): string => $state->label())
                    ->color(fn (RfqStatus $state): string => $state->color()),
                TextColumn::make('created_at')->label('Submitted')->date('d M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(collect(RfqStatus::cases())->mapWithKeys(fn (RfqStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    Action::make('startReview')->label('Start review')->icon('heroicon-o-play-circle')->color('info')
                        ->visible(fn (CompanyInquiry $r): bool => $r->status === RfqStatus::New && static::canReview())
                        ->action(fn (CompanyInquiry $record) => static::run(fn () => app(InquiryTriageService::class)->startReview($record, auth()->user()), 'Review started')),

                    Action::make('approve')->label('Approve')->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()
                        ->visible(fn (CompanyInquiry $r): bool => in_array($r->status, [RfqStatus::New, RfqStatus::InReview], true) && static::canReview())
                        ->action(fn (CompanyInquiry $record) => static::run(fn () => app(InquiryTriageService::class)->approve($record, auth()->user()), 'Inquiry approved')),

                    Action::make('reject')->label('Reject')->icon('heroicon-o-x-circle')->color('danger')
                        ->visible(fn (CompanyInquiry $r): bool => in_array($r->status, [RfqStatus::New, RfqStatus::InReview, RfqStatus::Spam], true) && static::canReview())
                        ->schema([Textarea::make('reason')->required()->maxLength(500)])
                        ->action(fn (CompanyInquiry $record, array $data) => static::run(fn () => app(InquiryTriageService::class)->reject($record, $data['reason'], auth()->user()), 'Inquiry rejected')),

                    Action::make('markSpam')->label('Mark spam')->icon('heroicon-o-no-symbol')->color('danger')
                        ->visible(fn (CompanyInquiry $r): bool => in_array($r->status, [RfqStatus::New, RfqStatus::InReview], true) && static::canReview())
                        ->action(fn (CompanyInquiry $record) => static::run(fn () => app(InquiryTriageService::class)->markSpam($record, auth()->user()), 'Marked as spam')),

                    Action::make('close')->label('Close')->icon('heroicon-o-archive-box')->color('gray')->requiresConfirmation()
                        ->visible(fn (CompanyInquiry $r): bool => $r->status !== RfqStatus::Closed && static::canReview())
                        ->action(fn (CompanyInquiry $record) => static::run(fn () => app(InquiryTriageService::class)->close($record, auth()->user()), 'Inquiry closed')),
                ])->label('Triage')->icon('heroicon-m-ellipsis-vertical'),
            ]);
    }

    protected static function run(callable $callback, string $message): void
    {
        $callback();
        Notification::make()->title($message)->success()->send();
    }

    protected static function canReview(): bool
    {
        return (bool) auth()->user()?->can('inquiries.review');
    }
}
```

- [ ] **Step 4: Write `ListInquiries` page**

```php
<?php

namespace App\Filament\Resources\Inquiries\Pages;

use App\Filament\Resources\Inquiries\InquiryResource;
use Filament\Resources\Pages\ListRecords;

class ListInquiries extends ListRecords
{
    protected static string $resource = InquiryResource::class;
}
```

- [ ] **Step 5: Write `InquiryResource`**

```php
<?php

namespace App\Filament\Resources\Inquiries;

use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Filament\Resources\Inquiries\Tables\InquiriesTable;
use App\Models\CompanyInquiry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class InquiryResource extends Resource
{
    protected static ?string $model = CompanyInquiry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Leads';

    protected static ?string $navigationLabel = 'Inquiries';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('inquiries.review');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return InquiriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInquiries::route('/'),
        ];
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/InquiryModerationTest.php`
Expected: PASS (2 tests)

- [ ] **Step 7: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 8: Commit**

```bash
git add app/Filament/Resources/Inquiries tests/Feature/InquiryModerationTest.php
git commit -m "Add admin InquiryResource with triage actions"
```

---

### Task 4: Exporter `LeadForm` — show the buyer's actual request

**Files:**
- Modify: `app/Filament/Exporter/Resources/Leads/Schemas/LeadForm.php`
- Test: `tests/Feature/LeadDetailTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Models\Lead;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\User;

it('shows the inquiry message and phone on an inquiry-sourced lead', function () {
    $company = Company::factory()->create();
    $member = User::factory()->create();
    $member->companies()->attach($company, ['role' => 'owner']);

    $inquiry = CompanyInquiry::create([
        'company_id' => $company->id,
        'name' => 'Bob Buyer',
        'email' => 'bob@acme.test',
        'phone' => '+15551234567',
        'message' => 'We need 40ft containers of kiln-dried sapele monthly.',
        'status' => 'new',
    ]);

    $lead = Lead::create([
        'company_id' => $company->id,
        'company_inquiry_id' => $inquiry->id,
        'source' => 'inquiry',
        'status' => 'new',
        'buyer_name' => 'Bob Buyer',
        'buyer_email' => 'bob@acme.test',
        'last_activity_at' => now(),
    ]);

    $this->actingAs($member)
        ->get("/dashboard/leads/{$lead->id}/edit")
        ->assertOk()
        ->assertSee('40ft containers of kiln-dried sapele')
        ->assertSee('+15551234567');
});

it('shows the requested RFQ items on an rfq-sourced lead', function () {
    $company = Company::factory()->create();
    $member = User::factory()->create();
    $member->companies()->attach($company, ['role' => 'owner']);

    $rfq = Rfq::create([
        'reference_code' => 'RFQ-2026-TEST1',
        'buyer_name' => 'Alice Buyer',
        'buyer_email' => 'alice@acme.test',
        'status' => 'approved',
        'visibility' => 'public',
    ]);
    RfqItem::create([
        'rfq_id' => $rfq->id,
        'species_text' => 'Iroko',
        'form' => 'sawn',
        'quantity' => 30,
        'unit' => 'm3',
    ]);

    $lead = Lead::create([
        'company_id' => $company->id,
        'rfq_id' => $rfq->id,
        'source' => 'rfq',
        'status' => 'new',
        'buyer_name' => 'Alice Buyer',
        'buyer_email' => 'alice@acme.test',
        'last_activity_at' => now(),
    ]);

    $this->actingAs($member)
        ->get("/dashboard/leads/{$lead->id}/edit")
        ->assertOk()
        ->assertSee('Iroko');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/LeadDetailTest.php`
Expected: FAIL — message/phone/species text not present on the page

- [ ] **Step 3: Add the "Buyer request" section to `LeadForm`**

Replace the full contents of `app/Filament/Exporter/Resources/Leads/Schemas/LeadForm.php`:

```php
<?php

namespace App\Filament\Exporter\Resources\Leads\Schemas;

use App\Enums\LeadStatus;
use App\Models\Lead;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Buyer')
                    ->columns(3)
                    ->schema([
                        TextInput::make('buyer_name')->disabled(),
                        TextInput::make('buyer_email')->disabled(),
                        TextInput::make('buyer_country_code')->label('Country')->disabled(),
                    ]),

                Section::make('Buyer request')
                    ->visible(fn (?Lead $record): bool => $record !== null && ($record->companyInquiry !== null || $record->rfq !== null))
                    ->schema([
                        Textarea::make('inquiry_message')
                            ->label('Message')
                            ->disabled()
                            ->rows(4)
                            ->columnSpanFull()
                            ->visible(fn (?Lead $record): bool => $record?->companyInquiry !== null)
                            ->formatStateUsing(fn (?Lead $record): ?string => $record?->companyInquiry?->message),
                        TextInput::make('inquiry_phone')
                            ->label('Phone')
                            ->disabled()
                            ->visible(fn (?Lead $record): bool => $record?->companyInquiry?->phone !== null)
                            ->formatStateUsing(fn (?Lead $record): ?string => $record?->companyInquiry?->phone),
                        Textarea::make('rfq_items')
                            ->label('Requested items')
                            ->disabled()
                            ->rows(3)
                            ->columnSpanFull()
                            ->visible(fn (?Lead $record): bool => $record?->rfq !== null)
                            ->formatStateUsing(fn (?Lead $record): ?string => $record?->rfq?->items->map(fn ($item) => $item->label())->join("\n")),
                    ]),

                Section::make('Pipeline')
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->options(collect(LeadStatus::cases())->mapWithKeys(fn (LeadStatus $s) => [$s->value => $s->label()])->all())
                            ->required(),
                        TextInput::make('value_amount')->label('Estimated value')->numeric(),
                        Textarea::make('notes')->label('Private notes')->rows(4)->columnSpanFull()
                            ->helperText('Visible only to your company.'),
                    ]),
            ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/LeadDetailTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: PASS, no regressions

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Exporter/Resources/Leads/Schemas/LeadForm.php tests/Feature/LeadDetailTest.php
git commit -m "Show buyer inquiry message/phone and RFQ items on exporter Lead detail"
```

---

### Task 5: Deploy to production

**Files:** none (deployment only)

- [ ] **Step 1: Push to GitHub**

```bash
git push origin master
```

- [ ] **Step 2: Pull and rebuild on the VPS**

SSH to `2.24.130.148` as `timberhub` (or root, then `sudo -u timberhub`) in `/home/timberhub/htdocs/www.cameroontimberhub.com` and run:

```bash
git pull origin master
composer install --no-dev --optimize-autoloader --no-interaction
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Expected: seeder output shows `Seeding database.` with no errors (idempotent — safe to re-run); no errors from composer/artisan.

- [ ] **Step 3: Verify in production**

```bash
curl -sk --resolve www.cameroontimberhub.com:443:2.24.130.148 https://www.cameroontimberhub.com/admin/inquiries -o /dev/null -w "HTTP_CODE:%{http_code}\n"
```

Expected: `HTTP_CODE:302` (redirect to login — confirms the route exists and is protected, since the curl call is unauthenticated).

- [ ] **Step 4: Restart the queue worker** (picks up any changed code paths used by queued jobs)

```bash
sudo systemctl restart timberhub-queue
sudo systemctl is-active timberhub-queue
```

Expected: `active`

---

## Self-Review Notes

- **Spec coverage:** `InquiryTriageService` (Task 1) ✅, `CompanyInquiryPolicy` + permission (Task 2) ✅ — named `CompanyInquiryPolicy` not `InquiryPolicy` to match this codebase's `{Model}Policy` convention (confirmed against `RfqPolicy`/`CompanyPolicy`/etc.), `InquiryResource` + triage table (Task 3) ✅, `LeadForm` buyer-request section for both inquiry and RFQ sources (Task 4) ✅, deployment (Task 5) ✅. All spec test cases covered.
- **Permission naming deviation:** `CompanyInquiryPolicy::viewAny/view` check `inquiries.review` directly (not a separate `inquiries.view`), because `RfqPolicy` was found to reference a `rfqs.view` permission that was never seeded (a pre-existing dead-code inconsistency in this codebase, out of scope to fix) — this plan does not repeat that mistake.
- **Placeholder scan:** none found — every step has concrete code.
- **Type consistency:** `InquiryTriageService` methods (`startReview`, `approve`, `reject`, `markSpam`, `close`) used identically in Task 1 (definition) and Task 3 (`InquiriesTable` actions). `CompanyInquiry::status` cast to `RfqStatus` (existing, unchanged). `Lead::companyInquiry()`/`Lead::rfq()` relations (existing, unchanged) used as-is in Task 4.
