# Feature Flags Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Install Laravel Pennant and prove, against a real feature in this codebase, that a flag can be enforced at the authorisation layer (middleware) rather than only hidden in Blade. This is item 0.3 of `docs/GAP_PLAN.md`.

**Architecture:** Pennant's `database` driver (persisted, admin-editable flags) backed by a `features` table. One class-based feature, `App\Features\DemoLoginsEnabled`, replaces the ad-hoc `config('demo.enabled')` check that currently gates the one-click demo-login route. The actual authorisation boundary moves to a new route middleware, `EnsureDemoLoginsEnabled`, applied to `POST /demo-login/{persona}`. The existing Blade `@if` in `resources/views/auth/login.blade.php` is updated to read the same flag but stays purely cosmetic — it only decides whether to draw three buttons, never whether the route accepts a request.

**Tech Stack:** Laravel 13, Pennant (new dependency), PostgreSQL, Pest.

Reference: `docs/GAP_PLAN.md` item 0.3, `docs/AUDIT.md` ("No feature-flag infrastructure" finding, lines 97-99).

---

## Scope decision (read before executing)

`docs/GAP_PLAN.md` item 0.3 points at three future gates — §5 carbon trading, §6 `regulatory_cleared` per sponsorship structure, §7.6's telematics feed — none of which exist in the codebase yet (all are marked 0 BUILT in `docs/AUDIT.md`). Building a flag class for `carbon-trading-enabled` today would gate nothing: there is no carbon-trading code path to protect, so a test proving the gate "works" would only prove that a boolean reads back correctly, not that anything real is blocked. That is exactly the kind of busywork this plan should avoid.

Investigation found one genuine feature flag already living in the codebase, in disguise: `config('demo.enabled')` (env `DEMO_LOGINS_ENABLED`), which gates `POST /demo-login/{persona}` (`app/Http/Controllers/Auth/DemoLoginController.php`, `routes/web.php:171-174`) and the demo-login buttons on the login page (`resources/views/auth/login.blade.php:164`). It is a real, working, security-relevant on/off switch — the `admin` persona is a genuine `super_admin` — and its current enforcement already lives partly in the controller (`abort_unless(config('demo.enabled') === true, 404)`) and partly, redundantly, in Blade. That is precisely the shape item 0.3 is warning about: a config-boolean flag whose only "infrastructure" is `env()`, not admin-configurable without a deploy, and not routed through any shared flag mechanism other engineers could reuse for the next flag.

**This plan migrates `DEMO_LOGINS_ENABLED` onto Pennant** as the proof-of-concept gate:
- It is real and exists today, so the test that proves "flag off ⇒ blocked" proves something true, not synthetic.
- It already has a security write-up in the controller docblock explaining why hiding the Blade button is not the boundary — this plan turns that stated intent into an actually-enforced middleware boundary instead of a controller-level `abort_unless` that a future edit could silently drop.
- It establishes the `App\Features\*` class pattern and the `database` driver so the real future flags (`carbon-trading-enabled`, `regulatory-cleared`, `telematics-feed-enabled`) in items 2.x/5.x/7.x have a working, tested mechanism to plug into rather than inventing one from scratch under deadline pressure later.

`config('demo.enabled')` is **not removed** — `config/demo.php` still holds the persona allow-list (`personas`), which has nothing to do with flag state. Only the boolean gate moves to Pennant.

**Explicitly out of scope for this plan** (tracked as follow-up, not silently dropped):
- A Filament UI for admins to toggle flags. Pennant's `database` driver makes flags persisted and editable right now via `Feature::activate()`/`Feature::deactivate()` (tinker, a console command, or a future admin action) — an admin-facing Filament resource for flag management is real, separate work with its own design questions (which flags are user-visible, audit logging of who flipped a flag) and does not block items 2.x/5.x/7.x from gating on Pennant.
- The actual `carbon-trading-enabled`, `regulatory-cleared`, and `telematics-feed-enabled` flag classes. They gate features that do not exist yet (0 BUILT per the audit); defining them now would mean testing against code that isn't there. Each should be added in the same commit/PR that builds the feature it gates, following the pattern this plan establishes (Task 2's feature class, Task 3's middleware).

---

## Judgment calls, stated explicitly

**1. Driver: `database`, not `array`.** Pennant ships two drivers out of the box. `array` is in-memory per-request — it never persists, so an admin flipping a flag would need a code deploy every time, which is worse than the `env()` toggle this plan replaces. `database` persists resolved flag values in a `features` table and lets any code call `Feature::activate()` / `Feature::deactivate()` to change them without a deploy. Pennant's own publishable migration creates:

```php
Schema::create('features', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('scope');
    $table->text('value');
    $table->timestamps();

    $table->unique(['name', 'scope']);
});
```

`name` is the feature key (e.g. `App\Features\DemoLoginsEnabled`), `scope` identifies who the resolved value applies to (a serialized model key, or the literal string `null` for a global/unscoped flag — which is what this plan uses, since demo-login availability is a platform-wide switch, not per-user), and `value` is the JSON-encoded resolved value (`true`/`false` here). `config/pennant.php`'s `default` store is set to `database` via `PENNANT_STORE` (defaulting to `database` if unset), matching the brief's requirement that `regulatory_cleared`-class flags be admin-configurable and persisted, not a code-only toggle.

**2. Enforcement point: middleware, not the controller and never only Blade.** The gap-plan item is explicit: "enforced in policies/middleware, never only in Blade." This plan:
- Adds `App\Http\Middleware\EnsureDemoLoginsEnabled`, applied to the `demo.login` route, as the actual authorisation boundary. It calls `Feature::active(DemoLoginsEnabled::class)` and 404s if inactive — this runs *before* the controller, so a flagged-off feature never reaches application logic at all.
- Removes the redundant `abort_unless(config('demo.enabled') === true, 404)` line from `DemoLoginController` — with the middleware in place, duplicating the check in the controller using a *different* source of truth (`config()` vs `Feature::active()`) would be worse than removing it: it would look like defense-in-depth but could actually drift out of sync with the real flag.
- Updates the Blade `@if (config('demo.enabled') === true)` to `@if (Feature::active(DemoLoginsEnabled::class))`. This stays cosmetic by design — it only decides whether three buttons render. Proof that this alone is not the boundary: Task 3's test hits the route directly with the flag off and asserts 404, without ever touching the Blade template.

**3. A real test that proves the block happens at the right layer.** `tests/Feature/EnsureDemoLoginsEnabledTest.php` (Task 3) deactivates the Pennant feature and asserts the *route* returns 404 and that `auth()->check()` is false afterward — i.e. that the request never reached `Auth::login()` in the controller. A test that only asserted `Feature::active(DemoLoginsEnabled::class) === false` in isolation would prove the flag reads correctly but not that anything is actually blocked; this plan avoids that shape of test throughout.

---

## Test-environment safety (read before any task that runs the suite)

This project's Pest suite shares **one** PostgreSQL testing database (`cameroontimberhub_testing`) with no isolation between concurrent runs, and three other planning agents may be working in sibling worktrees against the same shared database right now. Every task below that runs `php artisan test`:
- Runs it in the **foreground only**, one run at a time — never in the background, never concurrently with another `php artisan test` invocation you or anything else might kick off.
- If you see `relation "X" already exists` or `relation "migrations" does not exist`, that is corruption from a concurrent run, not a bug in this plan's code. Repair with `php artisan migrate:fresh --env=testing --force` — but **only after verifying `--env=testing` genuinely targets `cameroontimberhub_testing`**, by running `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` first and confirming the output. A `.env.testing` file must exist for `--env=testing` to resolve correctly. Never run `migrate:fresh` without that verification step — earlier in this project's history, running it without `.env.testing` in place silently wiped the dev database instead of the test one.
- This plan does not itself run `migrate:fresh` in any task; new migrations are added with `php artisan migrate --env=testing` (additive) after the verification check above, never a `fresh`.

---

### Task 1: Install Pennant and publish its store

**Files:**
- Modify: `composer.json`, `composer.lock` (via `composer require`)
- Create: `config/pennant.php` (via `vendor:publish`)
- Create: `database/migrations/2026_08_28_120010_create_features_table.php` (renamed from Pennant's published migration, to fit this project's dated-migration convention — see `database/migrations/2026_08_28_100010_create_documents_table.php` for the existing naming pattern)

- [ ] **Step 1: Install the package**

Run: `composer require laravel/pennant`
Expected: composer resolves `laravel/pennant` (a `^1.x` release compatible with Laravel 13) and adds it to `composer.json`'s `require` block. Laravel's package auto-discovery registers `Laravel\Pennant\PennantServiceProvider` automatically — no entry is needed in `bootstrap/providers.php`.

- [ ] **Step 2: Publish Pennant's config and migration**

Run: `php artisan vendor:publish --provider="Laravel\Pennant\PennantServiceProvider"`
Expected output: two files created —
```
Copied File [vendor/laravel/pennant/config/pennant.php] To [config/pennant.php]
Copied Directory [vendor/laravel/pennant/database/migrations] To [database/migrations]
```
The published migration file will be named `<today's-timestamp>_create_features_table.php`. Rename it to `2026_08_28_120010_create_features_table.php` (`git mv` if it lands under version control already, otherwise a plain filesystem rename) so it sorts correctly alongside this project's other `2026_08_28_*` migrations and follows the project's `YYYY_MM_DD_HHMMSS_description.php` convention.

- [ ] **Step 3: Confirm the published files match what this plan expects**

Open `database/migrations/2026_08_28_120010_create_features_table.php` and confirm it creates a `features` table with `id`, `name`, `scope`, `value` (text), `created_at`/`updated_at`, and a unique index on `(name, scope)` — matching the shape documented in "Judgment calls" above. Open `config/pennant.php` and confirm it defines a `default` key reading `env('PENNANT_STORE', 'database')` and a `stores.database` entry with `'driver' => 'database'`. If the published file differs in a way that changes these guarantees, stop and reconcile before continuing — the rest of this plan assumes this exact shape.

- [ ] **Step 4: Set the store explicitly in the environment files**

Add to `.env.example` (and to `.env` / `.env.testing` if they are not gitignored placeholders — check first with `git check-ignore .env .env.testing`; do not create secrets files that don't already exist):
```
PENNANT_STORE=database
```
This is the same value as the config default, but stating it explicitly in `.env.example` documents the decision for the next engineer rather than relying on an implicit default.

- [ ] **Step 5: Migrate the new table in the testing database**

Follow the verification step under "Test-environment safety" above first. Then run:
```bash
php artisan migrate --env=testing
```
Expected: `2026_08_28_120010_create_features_table` appears in the `Migrating` / `Migrated` output. This is additive (`migrate`, not `migrate:fresh`) and safe to run against the shared testing database.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock config/pennant.php database/migrations/2026_08_28_120010_create_features_table.php .env.example
git commit -m "feat: install Laravel Pennant with the database driver"
```

---

### Task 2: The `DemoLoginsEnabled` feature class

**Files:**
- Create: `app/Features/DemoLoginsEnabled.php`
- Test: `tests/Feature/DemoLoginsEnabledFeatureTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Features\DemoLoginsEnabled;
use Illuminate\Support\Facades\Config;
use Laravel\Pennant\Feature;

it('defaults to the DEMO_LOGINS_ENABLED config value the first time it resolves', function () {
    Config::set('demo.enabled', true);

    expect(Feature::active(DemoLoginsEnabled::class))->toBeTrue();
});

it('defaults to false when config demo.enabled is false', function () {
    Config::set('demo.enabled', false);

    expect(Feature::active(DemoLoginsEnabled::class))->toBeFalse();
});

it('persists an explicit admin override independently of the config default', function () {
    Config::set('demo.enabled', false);

    // Resolve once so Pennant has a stored row, then override it — this is
    // the admin-configurable behaviour the `database` driver exists for:
    // the stored value wins over the config default from this point on.
    Feature::active(DemoLoginsEnabled::class);
    Feature::activate(DemoLoginsEnabled::class);

    expect(Feature::active(DemoLoginsEnabled::class))->toBeTrue();

    Feature::deactivate(DemoLoginsEnabled::class);

    expect(Feature::active(DemoLoginsEnabled::class))->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Follow "Test-environment safety" above (foreground only). Run:
`php artisan test tests/Feature/DemoLoginsEnabledFeatureTest.php`
Expected: FAIL — `Class "App\Features\DemoLoginsEnabled" not found`.

- [ ] **Step 3: Write the feature class**

```php
<?php

namespace App\Features;

use Illuminate\Support\Facades\Config;

/**
 * Whether the one-click demo-login route and buttons are available.
 *
 * Backed by Pennant's `database` driver, so once resolved the value is
 * persisted in the `features` table and can be flipped at runtime with
 * `Feature::activate(self::class)` / `Feature::deactivate(self::class)`
 * (e.g. from `php artisan tinker`) without a deploy. `resolve()` only
 * supplies the *initial* value the first time the flag is checked for a
 * given scope — it seeds the stored row from `DEMO_LOGINS_ENABLED` so the
 * env var remains the deploy-time default, but an explicit
 * activate/deactivate call always wins after that.
 *
 * Unscoped (global) on purpose: demo-login availability is a platform-wide
 * switch, not a per-user preference, so this is always checked as
 * `Feature::active(self::class)` — never `Feature::for($user)->active(...)`.
 */
class DemoLoginsEnabled
{
    public function resolve(mixed $scope): bool
    {
        return (bool) Config::get('demo.enabled', false);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/DemoLoginsEnabledFeatureTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Features/DemoLoginsEnabled.php tests/Feature/DemoLoginsEnabledFeatureTest.php
git commit -m "feat: add DemoLoginsEnabled Pennant feature"
```

---

### Task 3: Enforce the flag in middleware (the actual authorisation boundary)

**Files:**
- Create: `app/Http/Middleware/EnsureDemoLoginsEnabled.php`
- Modify: `bootstrap/app.php` (register the middleware alias, no other changes to the file)
- Modify: `routes/web.php:171-174` (apply the middleware to `demo.login`)
- Modify: `app/Http/Controllers/Auth/DemoLoginController.php` (remove the now-redundant `config()` check and its stale docblock claim)
- Test: `tests/Feature/EnsureDemoLoginsEnabledTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Features\DemoLoginsEnabled;
use Database\Seeders\DemoLoginSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DemoLoginSeeder::class);
});

it('blocks the route at the middleware layer when the flag is inactive, before any controller logic runs', function () {
    Feature::deactivate(DemoLoginsEnabled::class);

    $response = $this->post(route('demo.login', 'admin'));

    $response->assertNotFound();
    // If this had reached DemoLoginController::__invoke, the admin persona
    // (a genuine super_admin) would now be authenticated. It must not be.
    expect(auth()->check())->toBeFalse();
});

it('lets the route through to the controller when the flag is active', function () {
    Feature::activate(DemoLoginsEnabled::class);

    $response = $this->post(route('demo.login', 'buyer'));

    $response->assertRedirect('/account');
    expect(auth()->check())->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Follow "Test-environment safety" above. Run:
`php artisan test tests/Feature/EnsureDemoLoginsEnabledTest.php`
Expected: the first test FAILs — currently the route only checks `config('demo.enabled')` inside the controller, which is untouched by `Feature::deactivate()`, so the request reaches the controller, finds `config('demo.enabled')` still whatever the test suite's ambient value is, and the response does not reliably 404. (If `config('demo.enabled')` happens to be `false` in the test environment already, the second test fails instead, since the controller's config-based check has nothing to do with `Feature::activate()` and the route still 404s.) Either way, at least one assertion fails, confirming the middleware doesn't exist yet to make both true together.

- [ ] **Step 3: Write the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Features\DemoLoginsEnabled;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * The actual authorisation boundary for the demo-login route (see
 * DemoLoginController's docblock and docs/GAP_PLAN.md item 0.3): hiding the
 * login-page buttons is cosmetic, this is the gate. Applied directly to the
 * `demo.login` route so a flagged-off feature 404s before any controller
 * code — including the persona allow-list check and Auth::login() — runs.
 */
class EnsureDemoLoginsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Feature::active(DemoLoginsEnabled::class), 404);

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware alias**

In `bootstrap/app.php`, add the import and extend the existing `$middleware->alias([...])` call:

```php
use App\Http\Middleware\EnsureDemoLoginsEnabled;
```

```php
        $middleware->alias([
            'exporter.onboarded' => EnsureExporterOnboarded::class,
            'buyer' => EnsureBuyerAccount::class,
            'api.buyer' => EnsureApiBuyer::class,
            'demo.logins.enabled' => EnsureDemoLoginsEnabled::class,
        ]);
```

- [ ] **Step 5: Apply the middleware to the route**

In `routes/web.php`, change:

```php
    Route::post('/demo-login/{persona}', DemoLoginController::class)
        ->where('persona', 'buyer|supplier|admin')
        ->middleware('throttle:demo-login')
        ->name('demo.login');
```

to:

```php
    Route::post('/demo-login/{persona}', DemoLoginController::class)
        ->where('persona', 'buyer|supplier|admin')
        ->middleware(['throttle:demo-login', 'demo.logins.enabled'])
        ->name('demo.login');
```

- [ ] **Step 6: Remove the now-redundant check from the controller**

In `app/Http/Controllers/Auth/DemoLoginController.php`, delete this line from `__invoke()`:

```php
        abort_unless(config('demo.enabled') === true, 404);
```

Update the class docblock — replace the paragraph:

```
 * The whole feature is behind `config('demo.enabled')` (env
 * DEMO_LOGINS_ENABLED, default **false**). The flag is checked *here* as well
 * as in the Blade that draws the buttons, because hiding a control is not a
 * security boundary: with the flag off this route 404s for everyone.
```

with:

```
 * The whole feature is behind the `App\Features\DemoLoginsEnabled` Pennant
 * flag (seeded from env DEMO_LOGINS_ENABLED, default **false**), enforced by
 * the `demo.logins.enabled` route middleware — see
 * `App\Http\Middleware\EnsureDemoLoginsEnabled`. Hiding the buttons in Blade
 * is not a security boundary; with the flag off the middleware 404s the
 * route for everyone before this method ever runs.
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test tests/Feature/EnsureDemoLoginsEnabledTest.php`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Middleware/EnsureDemoLoginsEnabled.php bootstrap/app.php routes/web.php app/Http/Controllers/Auth/DemoLoginController.php tests/Feature/EnsureDemoLoginsEnabledTest.php
git commit -m "feat: enforce DemoLoginsEnabled at the middleware layer"
```

---

### Task 4: Make the Blade check cosmetic-only and migrate the existing test suite off `config('demo.enabled')`

**Files:**
- Modify: `resources/views/auth/login.blade.php:160-180`
- Modify: `tests/Feature/DemoLoginTest.php`

- [ ] **Step 1: Update the Blade check**

In `resources/views/auth/login.blade.php`, find:

```blade
                        {{-- One-click demo logins. Rendered only when
                             ... boundary. See config/demo.php. --}}
                        @if (config('demo.enabled') === true)
```

Replace the comment and condition with:

```blade
                        {{-- One-click demo logins. Rendered only when the
                             DemoLoginsEnabled flag is active — but this is
                             cosmetic, not the security boundary: the route
                             itself is gated by the demo.logins.enabled
                             middleware (App\Http\Middleware\EnsureDemoLoginsEnabled),
                             so hiding this block does not need to be relied
                             on for anything. See docs/GAP_PLAN.md item 0.3. --}}
                        @if (\Laravel\Pennant\Feature::active(\App\Features\DemoLoginsEnabled::class))
```

Leave the rest of the block (`@foreach (config('demo.personas', []) as $key => $persona)` and everything inside it) untouched — the persona list is unrelated to the flag.

- [ ] **Step 2: Update the existing demo-login test suite to drive the flag through Pennant instead of `Config::set`**

`tests/Feature/DemoLoginTest.php` currently sets `Config::set('demo.enabled', true)` in `beforeEach()` and `Config::set('demo.enabled', false)` inside the kill-switch test. Since gating now reads `Feature::active(DemoLoginsEnabled::class)` and not `config('demo.enabled')` directly, these need to drive the same outcome through Pennant. Make these edits:

Replace the imports at the top of the file:

```php
use App\Models\User;
use Database\Seeders\DemoLoginSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Config;
```

with:

```php
use App\Features\DemoLoginsEnabled;
use App\Models\User;
use Database\Seeders\DemoLoginSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Pennant\Feature;
```

Replace the `beforeEach`:

```php
beforeEach(function () {
    Config::set('demo.enabled', true);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DemoLoginSeeder::class);
});
```

with:

```php
beforeEach(function () {
    Feature::activate(DemoLoginsEnabled::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DemoLoginSeeder::class);
});
```

And inside `it('hides the buttons and refuses the route when disabled', ...)`, replace:

```php
    Config::set('demo.enabled', false);
```

with:

```php
    Feature::deactivate(DemoLoginsEnabled::class);
```

Leave every other test in the file untouched — they only depend on the flag being active, which `beforeEach` still guarantees.

- [ ] **Step 3: Run the full demo-login-related test suite**

Follow "Test-environment safety" above. Run:
```bash
php artisan test tests/Feature/DemoLoginTest.php tests/Feature/EnsureDemoLoginsEnabledTest.php tests/Feature/DemoLoginsEnabledFeatureTest.php
```
Expected: PASS, all tests across all three files (DemoLoginTest's full suite, including the rate-limit and idempotency tests, is unaffected by this change and should still pass unchanged).

- [ ] **Step 4: Run the full project test suite once, to confirm nothing else referenced `config('demo.enabled')` for gating**

Run: `php artisan test`
Expected: PASS. If anything outside the files this plan touched fails, it is almost certainly another place reading `config('demo.enabled')` as a gate — search with `grep -rn "demo.enabled" app/ resources/ routes/` and reconcile before continuing; do not leave a second, un-migrated gate reading the old config value.

- [ ] **Step 5: Commit**

```bash
git add resources/views/auth/login.blade.php tests/Feature/DemoLoginTest.php
git commit -m "refactor: make the demo-login Blade check cosmetic and drive tests through Pennant"
```

---

## Self-review

**Spec coverage:**
- "Install Laravel Pennant" — Task 1.
- "Flags enforced in policies/middleware, never only in Blade" — Task 3 (middleware is the boundary, controller's redundant check removed so there is exactly one source of truth) and Task 4 (Blade reduced to cosmetic, proven by Task 3's test never touching the Blade template).
- Driver choice justified against the brief's "admin-configurable and persisted" requirement for flags like `regulatory_cleared` — "Judgment calls" section, item 1.
- A real test proving a flagged-off feature is blocked at the policy/middleware layer, not just that the flag value reads correctly — Task 3, `EnsureDemoLoginsEnabledTest.php`, which asserts the HTTP response and `auth()->check()`, never just `Feature::active(...)` in isolation.
- Explicit judgment call on what the first proof-of-concept gate should be, with reasoning — "Scope decision" section.
- Standard test-environment safety notes in every task that runs tests — present in Tasks 1, 2, 3, 4 (each references the shared block near the top rather than repeating it, per the house style in `docs/superpowers/plans/2026-08-27-polymorphic-document-store.md`).

**Placeholder scan:** No "TBD"/"handle edge cases"/"similar to Task N" phrasing anywhere in the plan; every step that changes code shows the complete code, not a description of it.

**Type/name consistency:** `App\Features\DemoLoginsEnabled` (class name, namespace, and `resolve(mixed $scope): bool` signature) is identical everywhere it's referenced — Task 2's definition, Task 3's middleware and test imports, Task 4's Blade and test edits. The middleware alias `demo.logins.enabled` and the middleware class `EnsureDemoLoginsEnabled` are introduced once in Task 3 and referenced with the same names in Task 4's Blade comment and controller docblock. The `features` table shape stated in "Judgment calls" matches what Task 1 asks the engineer to verify against the actually-published migration.
