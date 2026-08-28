# RBAC Account Roles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the genuinely available part of gap-plan item 0.7 — seed the brief's §3.1 account-level roles (`admin`, `verifier`, `buyer`, `supplier`, `processor`, `artisan`, `carbon_developer`, `carbon_buyer`, `logistics_partner`) as real, assignable Spatie roles distinct from the existing staff-panel roles, and fix `EnsureBuyerAccount`'s single-capability assumption so one account can genuinely hold both a supplier (company-owning) and a buyer capability at once — the brief's own explicit example ("a sawmill both supplies and buys processing").

**Architecture:** `User` already has `HasRoles` (Spatie Permission, `web` guard) — the existing `RolesAndPermissionsSeeder` only seeds three *staff* roles (`admin`, `verification_officer`, `content_manager`, gating the `/admin` Filament panel). This plan adds a second, deliberately separate role vocabulary for *account* capability (what a user IS on the platform: buyer, supplier, etc.) on the same `HasRoles` mechanism — Spatie roles are many-to-many by design, so "one account, several roles" is a seeding/assignment problem, not a schema problem. `EnsureBuyerAccount`'s current redirect logic treats "has a company" and "wants to browse as a buyer" as mutually exclusive; this plan makes it check the account-capability roles instead of inferring from company ownership alone.

**Tech Stack:** Laravel 13, `spatie/laravel-permission`, Pest.

Reference: `docs/GAP_PLAN.md` item 0.7, `CTH_Claude_Code_Build_Brief.md` §3.1, `app/Http/Middleware/EnsureBuyerAccount.php`, `database/seeders/RolesAndPermissionsSeeder.php`, `app/Models/User.php`.

## Scope decision (read before executing)

**What the brief actually asks for, and what's real today:** §3.1 lists 9 roles: `admin`, `verifier`, `buyer`, `supplier`, `processor`, `artisan`, `carbon_developer`, `carbon_buyer`, `logistics_partner`. It also says explicitly: "extend with Processor/Manufacturer, Artisan, Project Developer, Carbon Buyer **in later phases**." Of the 9, only `admin` (as a staff role, already seeded) and the buyer/supplier capability (already load-bearing throughout the platform — companies, products, RFQs) have real, working functionality behind them TODAY. `processor`, `artisan`, `carbon_developer`, `carbon_buyer`, and `logistics_partner` correspond to entities and workflows (a `Processor` capacity model, carbon credit projects, logistics/telematics) that **do not exist in the codebase yet** — the same situation this session already handled for `OrganisationType`'s unmapped values (item 0.6, Option 3) and the Forest Sponsorship roles (item 0.5b). `verifier` is the one exception worth checking directly: `verification_officer` (a staff role) already exists and functionally covers what the brief calls `verifier` — confirm this in Task 1 rather than creating a second, redundant role name for the same capability.

**What this plan builds:** the role vocabulary seeded in full (all 9 names exist as real `Role` rows, so nothing has to be invented later just to assign it), but only `buyer` and `supplier` are actually wired to real behaviour — the fix to `EnsureBuyerAccount` that lets one account hold both. The other 5 non-admin, non-verifier roles are seeded and assignable (an admin can tag a user `processor` today, for future use) but have no gate or feature checking them yet, exactly as honest as `docs/CERTIFICATE_SPEC.md`'s `api` entitlement was left "recorded but unenforceable" in item 0.9c/0.9d rather than wired against nothing.

**Explicitly deferred, tracked as `0.7b`:** any actual feature-gating on `processor`/`artisan`/`carbon_developer`/`carbon_buyer`/`logistics_partner` — blocked on the underlying entities (Processor directory, Carbon Project, Logistics/telematics) being built first, in whichever later phase the gap plan already schedules them.

---

### Task 1: Seed the account-capability roles

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Test: `tests/Feature/AccountRoleSeederTest.php`

- [ ] **Step 1: Verify against reality first**

```bash
grep -n "verification_officer\|verifier" database/seeders/RolesAndPermissionsSeeder.php app/Policies/*.php
```

Confirm `verification_officer` already covers everything the brief's `verifier` role would need (review/approve verification requests — it should, per item 0.2's `VerificationPolicy` reusing `verification.review`). If it genuinely doesn't cover something, note that in your final report rather than silently adding a redundant `verifier` role — but the expectation, stated plainly, is that it already does and no new `verifier` role is needed, only the 8 non-staff account-capability roles below.

- [ ] **Step 2: Write the failing test**

```php
<?php

use Spatie\Permission\Models\Role;

it('seeds all 8 account-capability roles from brief section 3.1, distinct from the staff panel roles', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    $accountRoles = ['buyer', 'supplier', 'processor', 'artisan', 'carbon_developer', 'carbon_buyer', 'logistics_partner'];

    foreach ($accountRoles as $role) {
        expect(Role::where('name', $role)->where('guard_name', 'web')->exists())->toBeTrue("Missing account role: {$role}");
    }

    // Staff roles remain untouched and distinct.
    expect(Role::where('name', 'admin')->exists())->toBeTrue()
        ->and(Role::where('name', 'verification_officer')->exists())->toBeTrue();
});

it('lets one user hold both the buyer and supplier account roles at once', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $user = \App\Models\User::factory()->create();

    $user->assignRole('buyer', 'supplier');

    expect($user->fresh()->hasRole('buyer'))->toBeTrue()
        ->and($user->fresh()->hasRole('supplier'))->toBeTrue();
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/AccountRoleSeederTest.php`
Expected: FAIL — the account-capability roles don't exist yet.

- [ ] **Step 4: Extend the seeder**

In `database/seeders/RolesAndPermissionsSeeder.php`, add a new constant and seeding step alongside the existing staff-role seeding (read the seeder's `run()` method first to match its exact existing loop/creation style — likely `Role::firstOrCreate(['name' => ..., 'guard_name' => 'web'])` per the file's own established pattern for the staff roles):

```php
    /**
     * Account-capability roles (brief §3.1) -- what a USER account can DO on
     * the platform, distinct from the staff roles above which gate the
     * /admin Filament panel. Spatie roles are many-to-many, so one user can
     * hold several of these at once (the brief's own example: a sawmill
     * account is both `supplier` and `buyer`).
     *
     * Only `buyer` and `supplier` are wired to real behaviour today
     * (EnsureBuyerAccount). The rest are seeded so they exist and can be
     * assigned, but have no feature gate yet -- see gap-plan item 0.7b for
     * why (each depends on an entity/workflow that doesn't exist yet:
     * Processor directory, Carbon Project, Logistics/telematics).
     */
    public const ACCOUNT_ROLES = [
        'buyer',
        'supplier',
        'processor',
        'artisan',
        'carbon_developer',
        'carbon_buyer',
        'logistics_partner',
    ];
```

Add a loop seeding `self::ACCOUNT_ROLES` the same way the existing staff roles are seeded, in `run()`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/AccountRoleSeederTest.php`
Expected: PASS (2 tests).

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** this worktree shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. Before any `migrate:fresh`, verify `--env=testing` genuinely resolves to `cameroontimberhub_testing` via `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` — never skip this, it has silently wiped the dev database before in this project.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty
git add database/seeders/RolesAndPermissionsSeeder.php tests/Feature/AccountRoleSeederTest.php
git commit -m "Seed the brief's 8 account-capability roles (buyer/supplier/processor/artisan/carbon_developer/carbon_buyer/logistics_partner)"
```

---

### Task 2: Auto-assign `buyer`/`supplier` on the real triggers, and fix `EnsureBuyerAccount`'s exclusivity

**Files:**
- Modify: `app/Http/Middleware/EnsureBuyerAccount.php`
- Modify: wherever a `User` is attached to a `Company` as its first owner (find via `grep -rn "companies()->attach\|companies()->save\|CompanyUser::create" app` — likely a registration action/controller; read it first, do not guess the file)
- Test: `tests/Feature/EnsureBuyerAccountTest.php` — extend the existing file if one exists (`find tests -iname "*BuyerAccount*"` first), otherwise create it matching this codebase's real middleware-test conventions (check an existing middleware test for the pattern, e.g. `tests/Feature/EnsureDemoLoginsEnabledTest.php` from item 0.3).

- [ ] **Step 1: Verify against reality first**

Read `app/Http/Middleware/EnsureBuyerAccount.php` in full (already shown during planning — confirm it still matches: staff→`/admin`, has-a-company→`/dashboard`, else pass through). Find the real registration flow that creates a `Company` for a new user and attaches them as owner — read it to find the exact right insertion point for `assignRole('supplier')`.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Models\Company;
use App\Models\User;

it('lets a company-owning user with the buyer role also reach buyer-only routes, instead of always redirecting to /dashboard', function () {
    $user = User::factory()->create();
    $user->assignRole('supplier', 'buyer');
    $company = Company::factory()->create();
    $company->users()->attach($user->id, ['role' => 'owner']); // adjust to the real company_user pivot shape found in Step 1

    $response = $this->actingAs($user)->get(route('rfq.create')); // a real buyer-only route gated by EnsureBuyerAccount -- confirm this route name is actually gated by it via routes/web.php before using it

    $response->assertOk(); // was previously a redirect to /dashboard purely because the user owns a company
});

it('still redirects a company-owning user with no buyer role to /dashboard, unchanged behaviour', function () {
    $user = User::factory()->create();
    $user->assignRole('supplier');
    $company = Company::factory()->create();
    $company->users()->attach($user->id, ['role' => 'owner']);

    $response = $this->actingAs($user)->get(route('rfq.create'));

    $response->assertRedirect('/dashboard');
});
```

Confirm which real route `EnsureBuyerAccount` actually gates (Step 1's read of `routes/web.php` around the middleware's usage, already partially located during planning at `routes/web.php:220` and `:247`) and use that route name, not a guess.

- [ ] **Step 3: Run to verify it fails**

Run the new test file.
Expected: the first test fails (still redirected), the second passes (unchanged).

- [ ] **Step 4: Fix the middleware**

```php
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if ($user->hasAnyRole(self::STAFF_ROLES)) {
            return redirect()->to('/admin');
        }

        // A company-owning user who ALSO holds the `buyer` account role
        // (brief §3.1's "a sawmill both supplies and buys processing")
        // is allowed through to buyer-only routes rather than always being
        // bounced to /dashboard purely because they own a company.
        if ($user->companies()->exists() && ! $user->hasRole('buyer')) {
            return redirect()->to('/dashboard');
        }

        return $next($request);
    }
```

- [ ] **Step 5: Assign `supplier` when a user becomes a company owner**

At the real insertion point found in Step 1 (the registration/onboarding action that first attaches a user to a company), add `$user->assignRole('supplier');` immediately after the attachment succeeds — additive, does not change any existing return value or response shape of that action.

- [ ] **Step 6: Run tests to verify they pass**

Run the full modified test file.
Expected: PASS (both tests).

- [ ] **Step 7: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Http/Middleware/EnsureBuyerAccount.php tests/Feature/EnsureBuyerAccountTest.php
git add # the real registration/onboarding file modified in Step 5
git commit -m "Let a company-owning user also hold the buyer role instead of always redirecting to /dashboard"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** same shared-database rules as Task 1.

---

### Task 3: Record the deferred role-gating scope and self-review

**Files:**
- Modify: `docs/GAP_PLAN.md`

- [ ] **Step 1: Read the current Phase 0 table first** (item numbers may have shifted) and replace the existing `0.7` row:

```markdown
| 0.7 | ✅ **RBAC account roles — done (partial).** Seeded all 8 of the brief's §3.1 account-capability roles (`buyer`, `supplier`, `processor`, `artisan`, `carbon_developer`, `carbon_buyer`, `logistics_partner` — `verifier` already covered by the existing `verification_officer` staff role, confirmed not redundant), distinct from the existing staff-panel roles. `EnsureBuyerAccount` no longer unconditionally redirects a company-owning user away from buyer-only routes — a user holding both `supplier` and `buyer` (the brief's own "a sawmill both supplies and buys processing" example) can now reach both. `supplier` is auto-assigned when a user becomes a company owner. Only `buyer`/`supplier` are wired to real behaviour — see 0.7b for the rest. Plan: `docs/superpowers/plans/2026-08-28-rbac-account-roles.md`. | — | 0 (done) |
| 0.7b | **Feature-gate the remaining 5 account roles.** `processor`, `artisan`, `carbon_developer`, `carbon_buyer`, `logistics_partner` are seeded and assignable but check nothing yet — each depends on an entity/workflow that doesn't exist in the codebase (Processor directory §4.2, Carbon Project §6, Logistics/telematics §7). Wire each role's gate in the same commit as the feature it's meant to gate, matching how item 0.3's feature-flag classes were scoped. | Blocks nothing currently sold; these roles are informational only until their features exist. | TBD — one sub-item per feature as each is built |
```

- [ ] **Step 2: Commit**

```bash
git add docs/GAP_PLAN.md
git commit -m "Mark RBAC account roles (0.7) done for buyer/supplier; split remaining role-gating out as 0.7b"
```

No test run needed — documentation only.

---

## Self-Review Notes

- **Spec coverage:** all 9 §3.1 roles addressed — `admin` and `verifier`'s equivalent (`verification_officer`) already existed; the other 8 are seeded (Task 1); `buyer`/`supplier` are the only two wired to real behaviour, matching what actually has working functionality behind it today (Task 2); the other 5 are honestly deferred with a stated dependency (Task 3), not silently left unaddressed.
- **No fabricated infrastructure:** no gate is added for `processor`/`artisan`/`carbon_developer`/`carbon_buyer`/`logistics_partner` against features that don't exist.
- **Verify-before-code discipline:** Task 1 Step 1 and Task 2 Step 1 both require confirming real file/route shapes before writing code, matching the pattern that caught real wrong assumptions in items 0.6, 0.8, and 0.9c's own plans this session.
