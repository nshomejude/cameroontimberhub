# Company Inquiries — Admin Moderation + Exporter Lead Detail

Date: 2026-08-17
Status: Approved

## Problem

`CompanyInquiry` (public "contact this exporter" form submissions) has a model,
migration, and public intake flow (`InquiryController`, `IntakeService`), but:

1. **No admin visibility at all.** Unverified, spam, or otherwise
   non-actionable inquiries exist only in the `company_inquiries` table —
   there is no Filament resource, so admins cannot review, moderate, or spot
   abuse patterns across inquiries platform-wide (unlike RFQs, which have a
   full admin triage queue via `RfqResource`).
2. **Exporters can't read what the buyer actually wrote.** Once an inquiry's
   email is verified, `LeadFlowService::createFromInquiry` promotes it into a
   `Lead` that the exporter sees in their panel — but `LeadForm` only shows
   `buyer_name`, `buyer_email`, `buyer_country_code`. The inquiry's `message`
   and `phone` are never surfaced, so the exporter cannot see the actual
   request without querying the database directly.

## Scope

In scope:
- Admin `InquiryResource` (list + triage actions), mirroring `RfqResource`.
- `InquiryTriageService` state machine, mirroring `RfqTriageService`.
- `InquiryPolicy`.
- New `inquiries.review` permission, granted to `admin` and `super_admin`.
- Exporter `LeadForm`: add a read-only "Buyer request" section showing the
  inquiry message/phone (for `source = inquiry` leads) or the RFQ items (for
  `source = rfq` leads) — same underlying gap (Lead detail lacks the buyer's
  actual request), same file, trivial extra cost to fix both at once.
- Tests for all of the above.

Out of scope (unchanged in this round):
- Anti-spam review console, subscriptions admin view, RFQ routing
  visibility, document logs UI, slug redirects UI — separate specs.
- Any change to the public intake form or `IntakeService`.
- Editing/creating inquiries from the admin panel (moderation is
  state-transition only, matching the RFQ pattern).

## Design

### 1. `InquiryTriageService` (new, `app/Services/InquiryTriageService.php`)

Same shape as `RfqTriageService`. Reuses `RfqStatus` (already the cast type
of `CompanyInquiry::status`), so no new enum is needed.

```php
public const TRANSITIONS = [
    'new' => ['in_review', 'approved', 'rejected', 'spam'],
    'in_review' => ['approved', 'rejected', 'spam', 'closed'],
    'approved' => ['closed', 'rejected'],
    'rejected' => ['closed'],
    'spam' => ['rejected', 'closed'],
    'closed' => [],
];
```

Methods: `transition()`, `startReview()`, `approve()`, `reject(reason)`,
`markSpam()`, `close()`. Every transition logs via
`activity('inquiry')->performedOn($inquiry)->causedBy($actor)->event('status_changed')`,
matching the RFQ audit trail pattern. No `route()` method — an inquiry is
already scoped to the one company the buyer contacted.

### 2. `InquiryPolicy` (new, `app/Policies/InquiryPolicy.php`)

`viewAny`/`view` gated on `$user->can('inquiries.review')`. No
`create`/`update`/`delete` — moderation happens through triage actions, not
form edits (matches `RfqPolicy`).

### 3. Permission + role seeding

Add `'inquiries.review'` to `RolesAndPermissionsSeeder::PERMISSIONS` and to
the `admin` role's permission list (alongside `rfqs.triage`, `rfqs.route`).
`super_admin` gets it automatically (it holds every permission).

### 4. Admin `InquiryResource` (new, `app/Filament/Resources/Inquiries/`)

List-only (`canCreate`/`canEdit`/`canDelete` all `false`), navigation group
`'Leads'`, gated by `canViewAny(): auth()->user()?->can('inquiries.review')`.

Table columns: company (`legal_name`), buyer name, buyer email, message
(truncated, wrap), verified badge (derived from `email_verified_at`), status
badge (reusing `RfqStatus::label()`/`color()`), submitted date.

Filters: status (multi-select), company.

Triage actions (`ActionGroup`, same UX as `RfqsTable`): Start review →
Approve / Reject (with reason) / Mark spam → Close. Visibility rules mirror
`RfqsTable`'s per-status `visible()` closures, gated by
`auth()->user()?->can('inquiries.review')`.

Only one page: `index` (`ListInquiries`).

### 5. Exporter `LeadForm` — "Buyer request" section

New read-only `Section::make('Buyer request')` added to
`app/Filament/Exporter/Resources/Leads/Schemas/LeadForm.php`, placed above
the existing "Pipeline" section:

- For `source === 'inquiry'`: `Textarea::make('message')` and
  `TextInput::make('phone')`, both disabled, populated via
  `->formatStateUsing(fn (Lead $record) => $record->companyInquiry?->message)`
  (no schema change — `companyInquiry()` relation already exists on `Lead`).
- For `source === 'rfq'`: a read-only list of the RFQ's requested items via
  `$record->rfq?->items`, using the same `label()` formatting already used in
  `RfqsTable` (`$i->label()`).
- Section hidden entirely if neither relation resolves (defensive; should not
  happen in practice since `source` is always one of the two).

No changes to `LeadsTable` (list view) — this is a detail-view fix only, to
keep the list scannable.

### 6. Tests

- `tests/Unit/InquiryTriageServiceTest.php` — legal/illegal transitions,
  activity log entries written.
- `tests/Feature/InquiryModerationTest.php` — `InquiryResource` visible only
  to `inquiries.review` holders; triage actions perform the expected status
  transition; unauthorized users get 403.
- Extend `tests/Feature/ComplianceTest.php` or add
  `tests/Feature/LeadDetailTest.php` — exporter sees the inquiry message/RFQ
  items on the Lead edit page, scoped to their own company only (existing
  `dashboardOwned()` scoping already enforces this at the query level; the
  test confirms the new fields render).

## Error handling

- Illegal status transitions throw `RuntimeException` from
  `InquiryTriageService::transition()`, exactly like `RfqTriageService` —
  Filament surfaces this as a failed action notification, no silent failures.
- `LeadForm`'s new section uses `?->` throughout; a lead with neither
  relation loaded simply hides the section rather than erroring.

## Migration / rollout

No database migrations required — this is entirely new
service/policy/resource code plus one seeder addition
(`inquiries.review` permission). Existing `admin`/`super_admin` accounts gain
the new permission the next time `RolesAndPermissionsSeeder` runs (idempotent
— safe to re-run in production via `php artisan db:seed --class=RolesAndPermissionsSeeder --force`).
