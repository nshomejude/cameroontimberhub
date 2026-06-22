---
title: Cameroon Timber Hub — MVP Technical Specification
date: 2026-06-22
status: draft-for-review
version: MVP (V1)
synthesis: parallel domain authoring (10 sections) + reconciliation pass + adversarial critique
---

# Cameroon Timber Hub — MVP Technical Specification

> **Legal framing (applies platform-wide).** Cameroon Timber Hub reviews documents submitted by companies; it does **not** guarantee any company. Every public verification surface uses wording such as *"Documents reviewed by Cameroon Timber Hub based on information submitted by the company,"* and always shows a **verification date**, a **valid-until date**, and the reminder that *"buyers should conduct final due diligence before any transaction."*

## Overview & Positioning

Cameroon Timber Hub is a **verified B2B timber trade & trust platform** connecting Cameroonian timber exporters with international buyers. The MVP is built around four engines:

1. **Directory** — verified company profiles, searchable by species, region, and verification status.
2. **Verification & Compliance** — document upload + manual admin review + badges, with legally-safe wording and expiry tracking.
3. **Species SEO** — programmatic, crawlable species and directory pages that drive organic acquisition.
4. **RFQ Intake** — public buyer request-for-quote and inquiry forms (no buyer account required) that admins triage and route to verified exporters as leads.

The platform is a **modular monolith** on Laravel. The subscription revenue model is captured as data with manual upgrades in the MVP; live billing, inventory, quotations, invoicing, full CRM, commissions, broker tooling, reviews, and a public API are deferred to later versions.

## Decisions Log

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| 1 | Framework | Laravel 12.x (PHP 8.3), modular monolith | Team familiarity; strong admin/SaaS ecosystem |
| 2 | Database | PostgreSQL | JSONB, full-text search, GIN/partial indexes, audit/reporting strength |
| 3 | Public frontend | Blade + Livewire 3 + Tailwind + Alpine (TALL) | Server-rendered = best SEO; pairs with Filament |
| 4 | Admin + exporter dashboards | Two Filament 3 panels (`/admin`, `/dashboard`) on a **single `web` guard**, role-gated via `canAccessPanel()` | CRUD-heavy; auth, 2FA, tables, forms, policies for free; single guard avoids spatie per-guard duplication |
| 5 | Cache / queue / session | Redis via `predis/predis` | Performance; queue powers jobs + notifications |
| 6 | File storage | Private `documents` disk + signed URLs for documents; public disk for images; all behind a `DocumentService` | S3 swap later is config-only |
| 7 | Search | PostgreSQL full-text + Eloquent filters (Scout DB driver) | Meilisearch deferred to V2 (YAGNI) |
| 8 | RBAC / Audit / Sitemap | `spatie/laravel-permission`, `…/laravel-activitylog`, `…/laravel-sitemap` | Mature, well-supported |
| 9 | Subscriptions | Plans modeled as **data** + feature-gating + **manual** admin assignment | No payment gateway in MVP |
| 10 | Buyers | Public RFQ / inquiry forms, **no login** | Matches MVP acquisition focus; buyer accounts in V2 |
| 11 | Localization | **EN-first**, i18n-structured (no hardcoded user-facing strings) | French added in V2 with no rework |
| 12 | Production domain | Deferred; canonical/base URL derived from `APP_URL` | No hardcoded domain |
| 13 | Tests | Pest (feature + unit) | Coverage of domain logic and HTTP surfaces |

## MVP Scope

**In scope (V1):**

- Public SEO website + programmatic species and directory pages
- Verified company directory + public company profile pages
- Exporter registration & onboarding (account → company profile → species → contacts → gallery → submit for verification)
- Document upload + **manual** verification (admin review queue)
- Verification badges (issue / revoke / expiry, multi-type, public display with legally-safe wording)
- Species catalog (admin-managed) + per-species SEO pages
- Public RFQ + inquiry intake (no buyer login) with email verification + anti-spam + admin triage + routing to exporters
- Admin Control Center (Filament): companies, verification queue, documents, badges, species, RFQs, pages/SEO, users/roles, audit log, dashboard widgets
- Subscription plans as data + feature-gating + manual assignment
- Light exporter lead inbox (statuses: new / contacted / won / lost / dormant)
- Security & audit baseline (auth, 2FA, RBAC, activity log, signed URLs, rate limiting, soft deletes, document access logging)
- Document-expiry reminder jobs (90 / 60 / 30 / expired)

**Out of scope (deferred — see the final section for rationale and target versions):** buyer accounts/dashboards, inventory, quotation builder + PDF, invoicing, full CRM pipeline, commission tracking, broker module, reviews/reputation, live payment gateway, Meilisearch, SIGIF integration (structured fields only), public API, multi-country / mobile / ERP / traceability.

---

## Architecture & Application Structure

### 1. Architectural Overview

Cameroon Timber Hub is a **modular monolith**: a single Laravel 12.x application (PHP 8.3) deployed as one codebase and one process group, internally organized by business domain rather than by technical layer alone. A monolith is the correct MVP choice because the domains (companies, documents, verification, RFQs, leads, plans) are tightly coupled around a shared trust model and a single PostgreSQL database; splitting into services now would add operational cost with no benefit. Modularity is enforced through directory boundaries, domain-scoped Actions, and an Event/Listener spine rather than through separate deployables.

The application exposes **three distinct HTTP surfaces** over the same domain core, all backed by a **single `web` authentication guard**:

| Surface | Path | Stack | Access control | Audience |
|---|---|---|---|---|
| Public website | `/` | Custom TALL (Blade + Livewire 3 + Tailwind + Alpine), server-rendered | `web` guard (guest) | Buyers, search engines, anonymous visitors |
| Admin control center | `/admin` | Filament 3 panel | `web` guard + `canAccessPanel()` requiring a platform staff role | Cameroon Timber Hub staff |
| Exporter dashboard | `/dashboard` | Filament 3 panel (second panel) | `web` guard + `canAccessPanel()` requiring company membership | Verified/onboarding exporter accounts |

There is **one auth guard (`web`) over the `users` table** for all authenticated surfaces. The two Filament panels are not separated by distinct guards or cookies; they share the same session and are differentiated purely by the `canAccessPanel()` check on the `User` model plus role/membership. `/admin` requires a platform staff role (`super_admin`/`admin`/`verification_officer`/`content_manager`); `/dashboard` requires company membership (a `company_user` row). For MVP a user belongs to exactly one company (single-company dashboard, no Filament tenancy); multi-company is V2.

All three surfaces call into the **same domain layer** (Models, Actions, Services, Events). No surface contains business rules of its own; controllers, Livewire components, and Filament resources are thin and delegate to Actions. This guarantees that a state change (e.g. approving a document) behaves identically whether triggered from Filament admin, an exporter self-service flow, or a queued job, and that audit logging and notifications fire in exactly one place.

```
                 ┌──────────────────────────────────────────────┐
   Buyers /SEO ─▶│  Public TALL site  (app/Http, app/Livewire)   │
                 └───────────────┬──────────────────────────────┘
   CTH staff ───▶│  /admin Filament (app/Filament/Admin)         │
                 └───────────────┤
   Exporters ───▶│  /dashboard Filament (app/Filament/Exporter)  │
                 └───────────────┤
                                 ▼   (single `web` guard for all authed surfaces)
        ┌───────────────────────────────────────────────────────┐
        │  DOMAIN CORE                                           │
        │  Actions (state changes) ─▶ Events ─▶ Listeners        │
        │  Services (DocumentService, SearchService, …)          │
        │  Models + Policies                                     │
        └───────────────┬───────────────────────────────────────┘
                        ▼
        PostgreSQL (JSONB, FTS, GIN) · Redis (cache/queue/session)
        Private 'documents' disk · Queue worker · Scheduler
```

### 2. Directory & Domain Organization

The app uses Laravel's default `app/` autoload root with domain-oriented subnamespaces. The structure below is the recommended canonical layout; the **Data Model section remains the single source of truth for all table and column names** referenced by these classes.

```
app/
├── Models/                     # Eloquent models (one per table), with casts & relations
│   ├── Company.php
│   ├── CompanyDocument.php
│   ├── VerificationRequest.php
│   ├── VerificationBadge.php
│   ├── Species.php
│   ├── Rfq.php
│   ├── RfqCompany.php
│   ├── Lead.php
│   ├── Plan.php
│   ├── Subscription.php
│   ├── Page.php
│   └── User.php
│
├── Enums/                      # Backed string enums cast on models
│   ├── CompanyStatus.php        # draft|pending|verified|suspended|rejected|archived
│   ├── CompanyDocumentStatus.php
│   ├── DocumentVisibility.php
│   ├── VerificationRequestStatus.php
│   ├── VerificationBadgeStatus.php
│   ├── RfqStatus.php
│   ├── RfqCompanyStatus.php
│   ├── LeadStatus.php
│   └── SubscriptionStatus.php
│
├── Actions/                    # Single-purpose, invokable use-cases (state changes)
│   ├── Company/
│   │   ├── SubmitCompanyForReview.php
│   │   ├── VerifyCompany.php
│   │   ├── SuspendCompany.php
│   │   └── ArchiveCompany.php
│   ├── Documents/
│   │   ├── UploadCompanyDocument.php
│   │   ├── ApproveDocument.php
│   │   ├── RejectDocument.php
│   │   └── RequestDocumentCorrection.php
│   ├── Verification/
│   │   ├── OpenVerificationRequest.php
│   │   ├── ApproveVerificationRequest.php
│   │   ├── IssueBadge.php
│   │   ├── RevokeBadge.php
│   │   └── ExpireBadge.php
│   ├── Rfq/
│   │   ├── IntakeRfq.php
│   │   ├── ConfirmRfqEmail.php
│   │   ├── ApproveRfq.php
│   │   ├── MarkRfqSpam.php
│   │   └── RouteRfqToCompanies.php
│   ├── Lead/
│   │   ├── CreateLeadFromRfq.php
│   │   └── UpdateLeadStatus.php
│   └── Subscription/
│       ├── AssignPlan.php
│       └── CancelSubscription.php
│
├── Services/                   # Stateful/cross-cutting infrastructure wrappers
│   ├── DocumentService.php      # storage, signed URLs, MIME/size validation (disk-abstracted)
│   ├── SearchService.php        # PostgreSQL FTS + Scout DB filters for the directory
│   ├── BadgeService.php         # badge validity computation & display strings
│   ├── SlugService.php          # unique slug generation for companies/species/pages
│   └── AntiSpamService.php      # Turnstile verify + honeypot + rate-limit checks
│
├── Filament/
│   ├── Admin/                   # /admin panel: Resources, Pages, Widgets, RelationManagers
│   │   ├── Resources/ (Company, CompanyDocument, VerificationRequest, Rfq, Lead, Plan, Species, Page, User)
│   │   ├── Pages/ (Dashboard, ModerationQueue)
│   │   └── Widgets/ (PendingVerificationsWidget, RfqTriageWidget, ExpiringBadgesWidget)
│   └── Exporter/                # /dashboard panel: scoped to the authed user's company
│       ├── Resources/ (CompanyProfile, CompanyDocument, LeadInbox)
│       ├── Pages/ (OnboardingChecklist, SubscriptionStatus)
│       └── Widgets/ (VerificationStatusWidget, BadgeStatusWidget)
│
├── Livewire/                   # Public TALL interactive components
│   ├── Directory/ (CompanyDirectory.php, DirectoryFilters.php)
│   ├── Rfq/ (RfqForm.php, InquiryForm.php)
│   └── Species/ (SpeciesFilter.php)
│
├── Http/
│   ├── Controllers/            # Thin public controllers (SEO pages, signed-URL serving)
│   │   ├── HomeController.php
│   │   ├── CompanyProfileController.php
│   │   ├── SpeciesPageController.php
│   │   ├── ProgrammaticPageController.php
│   │   ├── SitemapController.php
│   │   └── DocumentDownloadController.php   # validates signed URLs + policy
│   ├── Middleware/
│   │   ├── SetLocale.php
│   │   └── EnsureExporterOnboarded.php
│   └── Requests/               # FormRequests for public RFQ/inquiry validation
│
├── Jobs/
│   ├── SendRfqRoutingNotifications.php
│   ├── ExpireBadgesJob.php                  # scheduled sweep
│   └── SendDocumentExpiryReminderJob.php     # scheduled reminders
│
├── Events/                     # Domain events emitted by Actions
│   ├── CompanyVerified.php
│   ├── DocumentApproved.php
│   ├── DocumentRejected.php
│   ├── BadgeIssued.php
│   ├── BadgeRevoked.php
│   ├── RfqApproved.php
│   ├── RfqRoutedToCompany.php
│   └── PlanAssigned.php
│
├── Listeners/                  # Notifications & side-effects (NOT audit — see §3)
│   ├── NotifyExporterOfVerification.php
│   ├── NotifyExporterOfRfq.php
│   └── SendBuyerRfqAcknowledgement.php
│
├── Policies/                   # Authorization, shared by all three surfaces
│   ├── CompanyPolicy.php
│   ├── CompanyDocumentPolicy.php
│   ├── RfqPolicy.php
│   └── LeadPolicy.php
│
├── Notifications/              # Mailables/notifications dispatched by listeners & jobs
└── Providers/
    ├── AppServiceProvider.php
    ├── Filament/AdminPanelProvider.php
    ├── Filament/ExporterPanelProvider.php
    └── EventServiceProvider.php   # explicit event→listener map
```

Tests live under `tests/` (Pest), split into `tests/Unit` (Actions, Services, Enums) and `tests/Feature` (HTTP surfaces, Livewire, Filament panels, jobs), with shared factories in `database/factories`.

### 3. The Action + Event/Listener Pattern

Every **state-changing workflow** is implemented as an **Action** — a single, invokable class with one public `execute()` (or `__invoke()`) method that encapsulates one business operation, runs inside a DB transaction, and is the *only* sanctioned way to mutate that part of the domain. Surfaces (Filament, Livewire, controllers, jobs) never write domain state directly; they construct inputs and call the Action. This keeps validation, invariants, audit, and notifications consistent regardless of the trigger.

**Flow contract for every Action:**

1. Authorize (via the relevant Policy / Gate) — actions assume the caller passes the acting `User` (or `null` for system/scheduled jobs).
2. Validate domain invariants (e.g. a badge can only be issued for a `verified` company with an `approved` verification request).
3. Mutate state within `DB::transaction()`, updating the canonical enum columns.
4. Dispatch a domain **Event** describing what happened.

**Audit** is centralized via `spatie/laravel-activitylog` configured in the model layer (the `LogsActivity` trait with a per-model `getActivitylogOptions()`), so every persisted attribute change is logged automatically as part of step 3 — actions do not write audit records by hand. Where an action needs richer context (who approved, the reason, the prior status), it adds a single `activity()->causedBy($user)->performedOn($model)->withProperties([...])->log('document.approved')` call. Audit is thus a property of *persistence + the action*, not scattered across controllers.

**Notifications and external side-effects** are centralized in **Listeners**. An Action's only outward responsibility is to dispatch its Event; Listeners (registered in `EventServiceProvider`) translate events into emails, queued jobs, and downstream state. This means:

- Notifications never duplicate when the same action is reachable from multiple surfaces.
- Heavy side-effects (emailing exporters, fanning out RFQ routing) run on the **Redis queue** because the relevant listeners implement `ShouldQueue`.
- Adding a new reaction to an event (e.g. a future analytics hook) requires only a new listener, never a change to the Action.

**Worked example — document approval:**

```
Admin clicks "Approve" in /admin
        └─▶ ApproveDocument::execute($document, $admin)
              1. CompanyDocumentPolicy::approve($admin, $document)  → gate
              2. assert status ∈ {pending, needs_correction}
              3. DB::transaction:
                   $document->update(['status' => Approved])
                   activitylog records the change + 'document.approved' entry
              4. event(new DocumentApproved($document, $admin))
                        │
   EventServiceProvider maps DocumentApproved →
        ├─ NotifyExporterOfVerification (ShouldQueue) → mail via Redis queue
        └─ (future) RecomputeCompanyReadiness listener
```

The same `ApproveDocument` action is invoked from a bulk Filament table action without any change to its body. Scheduled jobs (`ExpireBadgesJob`, `SendDocumentExpiryReminderJob`) likewise call Actions (`ExpireBadge`, etc.) rather than mutating models, so badge expiry is audited and can emit `BadgeRevoked`/expiry events identically to a manual revoke.

### 4. Required Composer Packages

| Package | Constraint | Justification |
|---|---|---|
| `laravel/framework` | `^12.0` | Core framework (locked). |
| `livewire/livewire` | `^3.0` | Server-rendered interactivity for the public TALL directory, RFQ/inquiry forms, and filters without a JS SPA. |
| `filament/filament` | `^3.0` | Powers both the `/admin` control center and the `/dashboard` exporter panel as two panels over the single `web` guard on a shared admin toolkit. |
| `spatie/laravel-permission` | `^6.0` | Roles & permissions for platform staff under the default `web` guard, backing `canAccessPanel()` and Policy checks. |
| `spatie/laravel-activitylog` | `^4.0` | Centralized, model-level audit trail satisfying the security/audit baseline (see §3). |
| `spatie/laravel-sitemap` | `^7.0` | Generates the XML sitemap for the public SEO site, including programmatic company/species pages. |
| `predis/predis` | `^2.0` | Pure-PHP Redis client (locked, avoids the phpredis extension) backing cache, queue, and session. |
| `laravel/scout` | `^10.0` | Search abstraction used with its **database driver** over PostgreSQL FTS for the directory; keeps a clean upgrade path while Meilisearch stays deferred. |
| `pestphp/pest` | `^3.0` | Test runner (locked); `pestphp/pest-plugin-laravel` for Laravel test helpers. |
| (Turnstile) custom integration | — | Anti-spam on public RFQ/inquiry forms. Use **Cloudflare Turnstile** via a small server-side verification call in `AntiSpamService` (HTTP POST to `siteverify`), avoiding a heavyweight package; a thin wrapper such as a Turnstile rule/middleware is acceptable. Combined with a honeypot field and Redis rate limiting. |

Supporting first-party packages assumed present: `laravel/tinker` (dev), and `barryvdh/laravel-debugbar` / `laravel/pint` / `nunomaduro/larastan` as dev tooling. No payment, Meilisearch, or buyer-auth packages are included (out of MVP scope).

### 5. Key Configuration

**Filesystem disks (`config/filesystems.php`).** A dedicated **private `documents` disk** stores all uploaded compliance documents; it is never web-served directly. Public, crawlable, non-sensitive images (company `logo_path`/`cover_path`, species `image_path`, gallery images) live on the **`public` disk**. Downloads of private documents go through `DocumentDownloadController` behind temporary **signed URLs** plus a Policy check, with the `documents` disk accessed exclusively through `DocumentService` so swapping `local` → `s3` is a single config change later.

```php
'disks' => [
    'public'    => [ 'driver' => 'local', 'root' => storage_path('app/public'), 'visibility' => 'public' ],
    'documents' => [
        'driver'     => 'local',
        'root'       => storage_path('app/private/documents'),
        'visibility' => 'private',
        'throw'      => true,
        // later swap to 's3' + bucket/region via env, no app code change
    ],
],
```

Only `company_documents` use the private `documents` disk; all company/species imagery uses the `public` disk.

**Cache, queue, session (Redis via predis).**
```
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=predis
```
A long-running queue worker processes `ShouldQueue` listeners and jobs; the scheduler (`routes/console.php`) registers `ExpireBadgesJob` (daily) and `SendDocumentExpiryReminderJob` (daily, with reminder windows derived from document/badge validity dates).

**Scout (database driver).** `SCOUT_DRIVER=database` so directory search uses PostgreSQL FTS + Eloquent filters; `Company` and `Species` models declare `toSearchableArray()`. Meilisearch is explicitly not configured.

**Mail.** SMTP transport driven entirely by env; all outbound mail (RFQ acknowledgements, verification notices, expiry reminders) is sent from queued Notifications. `MAIL_MAILER=smtp` with `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` configured per environment.

**Localization / i18n.** `APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`. All user-facing strings come from `lang/en/*.php` and `__()`/translation keys — **no hardcoded user-facing strings** anywhere (Blade, Livewire, Filament labels, Notifications). A `SetLocale` middleware reads a future locale source but defaults to `en`; the structure is ready for French in V2 with zero string refactoring.

**Two Filament panels on a single `web` guard.** Two `PanelProvider`s are registered, both bound to the default `web` guard. Panel access is gated by the `User` model's `canAccessPanel(Panel $panel)` method together with roles/membership — there are no separate `admin`/`exporter` guards, cookies, or sessions:

```php
// AdminPanelProvider
->id('admin')->path('admin')->authGuard('web')
->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
->discoverPages(...)->discoverWidgets(...);

// ExporterPanelProvider
->id('dashboard')->path('dashboard')->authGuard('web')
->discoverResources(in: app_path('Filament/Exporter/Resources'), for: 'App\\Filament\\Exporter\\Resources')
->middleware([..., EnsureExporterOnboarded::class]);
```

```php
// App\Models\User
public function canAccessPanel(Panel $panel): bool
{
    return match ($panel->getId()) {
        // /admin: platform staff only (spatie roles, default `web` guard)
        'admin'     => $this->hasAnyRole(['super_admin', 'admin', 'verification_officer', 'content_manager']),
        // /dashboard: company users (must have a company_user membership row)
        'dashboard' => $this->companies()->exists(),
        default     => false,
    };
}
```

There is no second guard in `config/auth.php`: the single `web` guard (eloquent / `users` provider) backs every authenticated surface, and the two panels are distinguished entirely by `canAccessPanel()`. Platform-staff authorization uses `spatie/laravel-permission` roles under this default guard; company-side authorization inside `/dashboard` is governed by the `company_user.role` value (owner full; manager limited; member read), not by spatie roles. For MVP a user belongs to exactly one company, so the `/dashboard` panel resolves that single company without Filament tenancy (multi-company is V2). The **public site is custom TALL and is never a Filament panel.**

**Canonical URL.** All canonical tags, sitemap entries, and signed URLs derive from `APP_URL`; **no domain is hardcoded** so the unknown production domain is a single env value.

### 6. Notable Environment Variables

```
APP_NAME="Cameroon Timber Hub"
APP_ENV=production
APP_KEY=base64:...
APP_URL=https://<production-domain>      # drives canonical/sitemap/signed URLs
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cameroon_timber_hub
DB_USERNAME=...
DB_PASSWORD=...

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

FILESYSTEM_DISK=local                     # default; the 'documents' disk is always private
DOCUMENTS_DISK=local                      # later: s3 (with AWS_* vars) — config swap only

SCOUT_DRIVER=database                     # PostgreSQL FTS; Meilisearch deferred

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="no-reply@<production-domain>"
MAIL_FROM_NAME="${APP_NAME}"

TURNSTILE_SITE_KEY=...                     # public RFQ/inquiry anti-spam
TURNSTILE_SECRET_KEY=...

# Later (deferred, present as placeholders only when moving documents to S3):
# AWS_ACCESS_KEY_ID= / AWS_SECRET_ACCESS_KEY= / AWS_DEFAULT_REGION= / AWS_BUCKET=
```

This architecture keeps every business rule in one auditable, event-emitting domain core while presenting three purpose-built surfaces over a single `web` guard, and it defers nothing that the MVP requires — storage, search, role-gated panel access, queueing, and i18n are all configured for the locked stack with clean upgrade seams (S3, French, Meilisearch) left as future config or additive work.

---

## Data Model

This section is the single source of truth for table and column names. All other sections MUST reference these names exactly. Conventions: tables `snake_case` plural; foreign keys `{singular}_id`; pivots alphabetical `singular_singular`; `created_at`/`updated_at` (`timestamptz`) on every entity; soft deletes (`deleted_at timestamptz NULL`) on `companies`, `company_documents`, `rfqs`, `users`. Primary keys are `bigint GENERATED ALWAYS AS IDENTITY` (Laravel `bigIncrements`) unless a UUID is explicitly noted. All enums are stored as `varchar` with a PHP enum cast plus a DB `CHECK` constraint enumerating the canonical values. Timestamps are `timestamptz` (Laravel `timestampsTz`). Postgres extensions assumed: `pg_trgm` (trigram fuzzy search) and the built-in `tsvector`/`to_tsvector` machinery for full-text.

### Conventions for full-text search

Searchable entities (`companies`, `species`, `pages`) carry a generated `tsvector` column maintained either by a `GENERATED ALWAYS AS (...) STORED` expression (preferred, Postgres 12+) or a `BEFORE INSERT/UPDATE` trigger when multiple weighted columns are combined. Each such column has a `GIN` index. Laravel Scout uses the database driver; the `tsvector` column is the canonical FTS surface and Scout filters layer Eloquent `where` clauses on top.

### Disk conventions

Two filesystem disks are used. The **`documents`** disk is **private** — `company_documents` files live here exclusively and are served only via signed URLs through `DocumentService`. The **`public`** disk holds crawlable, non-sensitive images (`companies.logo_path`, `companies.cover_path`, `company_gallery.image_path`, `species.image_path`, `pages.og_image_path`). Documents are NEVER on the public disk.

---

### 1. `users`

Application + panel users (admins, exporter staff). Buyers have NO accounts.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| name | varchar(255) | no | | |
| email | varchar(255) | no | | UNIQUE |
| email_verified_at | timestamptz | yes | null | |
| password | varchar(255) | no | | bcrypt/argon hash |
| phone | varchar(32) | yes | null | E.164 |
| locale | varchar(5) | no | 'en' | i18n; `fr` reserved for V2 |
| is_active | boolean | no | true | login gate |
| last_login_at | timestamptz | yes | null | |
| two_factor_secret | text | yes | null | Fortify/Filament 2FA |
| two_factor_recovery_codes | text | yes | null | |
| two_factor_confirmed_at | timestamptz | yes | null | |
| remember_token | varchar(100) | yes | null | |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |
| deleted_at | timestamptz | yes | null | soft delete |

Indexes: `UNIQUE(email)`; partial index `WHERE deleted_at IS NULL` on `email` for active-user lookups; `INDEX(is_active)`.

> Roles/permissions are NOT columns; they come from spatie pivot tables (see §29). There is ONE auth guard (`web`) over `users`. Panel access is determined by `canAccessPanel()`: `/admin` requires a platform staff spatie role (`super_admin`/`admin`/`verification_officer`/`content_manager`); `/dashboard` requires company membership (a `company_user` row), not a boolean.

---

### 2. `companies`

Core exporter entity. One company owns its profile, documents, badges.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| slug | varchar(180) | no | | UNIQUE, indexed |
| legal_name | varchar(255) | no | | registered legal name |
| trade_name | varchar(255) | yes | null | display/brand name |
| registration_number | varchar(100) | yes | null | RCCM |
| tax_id | varchar(100) | yes | null | NIU |
| sigif_operator_id | varchar(100) | yes | null | structured SIGIF field (no integration) |
| sigif_permit_numbers | jsonb | yes | null | array of permit/title strings (structured SIGIF fields) |
| status | varchar(20) | no | 'draft' | enum, see CHECK |
| description | text | yes | null | long profile copy |
| year_founded | smallint | yes | null | |
| employee_count | integer | yes | null | |
| annual_capacity_m3 | numeric(14,2) | yes | null | declared output m³/yr |
| email | varchar(255) | yes | null | public contact |
| phone | varchar(32) | yes | null | |
| website_url | varchar(255) | yes | null | |
| address_line | varchar(255) | yes | null | |
| city | varchar(120) | yes | null | |
| region | varchar(120) | yes | null | Cameroon region |
| country_code | char(2) | no | 'CM' | ISO-3166 alpha-2 |
| latitude | numeric(9,6) | yes | null | |
| longitude | numeric(9,6) | yes | null | |
| logo_path | varchar(512) | yes | null | public disk path |
| cover_path | varchar(512) | yes | null | public disk path |
| plan_id | bigint | yes | null | FK → plans (denormalized current plan; authoritative subscription in §27) |
| is_featured | boolean | no | false | editorial boost |
| verified_at | timestamptz | yes | null | last successful verification |
| verification_expires_at | timestamptz | yes | null | mirror of active badge validity |
| profile_completion | smallint | no | 0 | 0–100 onboarding % |
| meta_title | varchar(255) | yes | null | SEO override |
| meta_description | varchar(320) | yes | null | SEO override |
| search_vector | tsvector | yes | null | GENERATED/trigger: legal_name(A), trade_name(A), description(B), city/region(C) |
| created_by | bigint | yes | null | FK → users |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |
| deleted_at | timestamptz | yes | null | soft delete |

> No `name` column. Eloquent exposes a `name` accessor returning `trade_name ?: legal_name` for display. Contacts, export markets, and species are separate relations (`company_contacts`, `company_export_markets`, `company_species`) — there are NO JSONB `contacts`/`export_markets` columns on `companies`; visibility scopes use relations (e.g. `whereHas('contacts')`). Image columns (`logo_path`, `cover_path`) are on the PUBLIC disk; only `company_documents` are private.

CHECK: `companies_status_check CHECK (status IN ('draft','pending','verified','suspended','rejected','archived'))`.
FKs: `plan_id REFERENCES plans(id) ON DELETE SET NULL`; `created_by REFERENCES users(id) ON DELETE SET NULL`.
Indexes:
- `UNIQUE(slug)`
- `GIN(search_vector)` — full-text
- `GIN(legal_name gin_trgm_ops)` and `GIN(trade_name gin_trgm_ops)` — fuzzy typeahead
- `INDEX(status)`; partial `INDEX(status) WHERE deleted_at IS NULL`
- partial `INDEX(is_featured) WHERE is_featured = true`
- `INDEX(region)`, `INDEX(country_code)`
- partial directory index: `INDEX(verified_at) WHERE status = 'verified' AND deleted_at IS NULL`

---

### 3. `company_user` (pivot, with role)

Links panel users to companies they manage (exporter staff). Alphabetical pivot name.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies |
| user_id | bigint | no | | FK → users |
| role | varchar(20) | no | 'member' | company-scoped role: `owner`, `manager`, `member` |
| is_primary | boolean | no | false | primary contact user |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK: `company_user_role_check CHECK (role IN ('owner','manager','member'))`.
FKs: `company_id REFERENCES companies(id) ON DELETE CASCADE`; `user_id REFERENCES users(id) ON DELETE CASCADE`.
Indexes: `UNIQUE(company_id, user_id)`; `INDEX(user_id)`; partial `UNIQUE(company_id) WHERE is_primary = true` (one primary per company).

> Company-side authorization in `/dashboard` is governed by `company_user.role` (`owner` full company access; `manager` limited; `member` read), NOT by spatie roles. For MVP a user belongs to exactly ONE company. (`accountant`/`viewer` roles and multi-company tenancy are V2.)

---

### 4. `company_contacts`

Named contact people on a company profile (sales, logistics).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies |
| name | varchar(255) | no | | |
| title | varchar(120) | yes | null | job title |
| email | varchar(255) | yes | null | |
| phone | varchar(32) | yes | null | |
| whatsapp | varchar(32) | yes | null | |
| is_public | boolean | no | false | show on public profile |
| sort_order | smallint | no | 0 | |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

FK: `company_id REFERENCES companies(id) ON DELETE CASCADE`.
Indexes: `INDEX(company_id)`; `INDEX(company_id, sort_order)`.

---

### 5. `company_gallery`

Profile gallery images (mill, yard, products).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies |
| image_path | varchar(512) | no | | public disk path |
| caption | varchar(255) | yes | null | |
| alt_text | varchar(255) | yes | null | a11y/SEO |
| sort_order | smallint | no | 0 | |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

FK: `company_id REFERENCES companies(id) ON DELETE CASCADE`.
Indexes: `INDEX(company_id, sort_order)`.

---

### 6. `company_export_markets`

Countries/regions a company exports to (faceted directory + SEO).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies |
| country_code | char(2) | no | | ISO-3166 alpha-2 destination |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

FK: `company_id REFERENCES companies(id) ON DELETE CASCADE`.
Indexes: `UNIQUE(company_id, country_code)`; `INDEX(country_code)` (faceting).

---

### 7. `company_species` (pivot)

Which species a company offers, with commercial attributes. Alphabetical pivot name.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies |
| species_id | bigint | no | | FK → species |
| form | varchar(20) | yes | null | `logs`, `sawn`, `veneer`, `plywood`, `other` |
| grade | varchar(60) | yes | null | free-text grade |
| min_order_m3 | numeric(14,2) | yes | null | |
| price_amount | numeric(14,2) | yes | null | indicative |
| price_currency | char(3) | yes | null | XAF/USD/EUR/GBP/CNY |
| is_primary | boolean | no | false | headline species |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK: `company_species_form_check CHECK (form IS NULL OR form IN ('logs','sawn','veneer','plywood','other'))`; `company_species_currency_check CHECK (price_currency IS NULL OR price_currency IN ('XAF','USD','EUR','GBP','CNY'))`.
FKs: `company_id REFERENCES companies(id) ON DELETE CASCADE`; `species_id REFERENCES species(id) ON DELETE CASCADE`.
Indexes: `UNIQUE(company_id, species_id)`; `INDEX(species_id)` (species page → companies).

---

### 8. `company_social_links`

Social/media URLs for a company.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies |
| platform | varchar(30) | no | | `linkedin`,`facebook`,`instagram`,`youtube`,`x`,`wechat`,`other` |
| url | varchar(255) | no | | |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK: `company_social_links_platform_check CHECK (platform IN ('linkedin','facebook','instagram','youtube','x','wechat','other'))`.
FK: `company_id REFERENCES companies(id) ON DELETE CASCADE`.
Indexes: `UNIQUE(company_id, platform)`.

---

### 9. `document_types`

Catalog of required/optional document categories (RCCM, export licence, FSC cert, etc.). Seeded reference data.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| key | varchar(60) | no | | machine key, UNIQUE, e.g. `rccm`, `export_licence`, `fsc_cert` |
| name | varchar(150) | no | | display label (i18n key-friendly) |
| description | text | yes | null | guidance to exporter |
| is_required | boolean | no | false | required for verification |
| requires_expiry | boolean | no | false | document carries an expiry date |
| affects_verification | boolean | no | true | counts toward verification readiness |
| supports_sigif | boolean | no | false | document type carries SIGIF reference fields |
| sort_order | smallint | no | 0 | |
| is_active | boolean | no | true | |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

Indexes: `UNIQUE(key)`; `INDEX(is_active, sort_order)`.

---

### 10. `company_documents`

Uploaded files per company per type, with moderation status. Served only via signed URLs through `DocumentService`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies |
| document_type_id | bigint | no | | FK → document_types |
| original_filename | varchar(255) | no | | as uploaded |
| storage_path | varchar(512) | no | | private disk path |
| disk | varchar(40) | no | 'documents' | filesystem disk (private `documents` disk) |
| mime_type | varchar(120) | no | | |
| file_size | bigint | no | | bytes |
| checksum_sha256 | char(64) | yes | null | dedupe/integrity |
| status | varchar(20) | no | 'pending' | enum, see CHECK |
| visibility | varchar(20) | no | 'private' | enum, see CHECK |
| issue_date | date | yes | null | document issue date |
| expiry_date | date | yes | null | for expiry reminders |
| review_notes | text | yes | null | admin feedback |
| rejection_reason | text | yes | null | reason shown to exporter on reject/needs_correction |
| sigif_fields | jsonb | yes | null | structured SIGIF fields: registration number, permit number, reference, expiry |
| reviewed_by | bigint | yes | null | FK → users |
| reviewed_at | timestamptz | yes | null | |
| uploaded_by | bigint | yes | null | FK → users |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |
| deleted_at | timestamptz | yes | null | soft delete |

CHECK:
- `company_documents_status_check CHECK (status IN ('pending','approved','rejected','needs_correction'))`
- `company_documents_visibility_check CHECK (visibility IN ('private','admin_only','buyer_visible','public'))`

FKs: `company_id REFERENCES companies(id) ON DELETE CASCADE`; `document_type_id REFERENCES document_types(id) ON DELETE RESTRICT`; `reviewed_by REFERENCES users(id) ON DELETE SET NULL`; `uploaded_by REFERENCES users(id) ON DELETE SET NULL`.
Indexes:
- `INDEX(company_id, document_type_id)`
- `INDEX(status)`; partial `INDEX(status) WHERE status = 'pending' AND deleted_at IS NULL` (admin review queue)
- partial expiry index: `INDEX(expiry_date) WHERE expiry_date IS NOT NULL AND deleted_at IS NULL` (reminder scheduler)
- `INDEX(visibility)`

---

### 11. `document_access_logs`

Audit of who fetched/downloaded a private document (signed-URL access).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_document_id | bigint | no | | FK → company_documents |
| user_id | bigint | yes | null | FK → users (null = system/anon signed) |
| action | varchar(20) | no | 'view' | `view`, `download`, `signed_url_issued` |
| ip_address | inet | yes | null | |
| user_agent | varchar(512) | yes | null | |
| created_at | timestamptz | yes | null | |

CHECK: `document_access_logs_action_check CHECK (action IN ('view','download','signed_url_issued'))`.
FKs: `company_document_id REFERENCES company_documents(id) ON DELETE CASCADE`; `user_id REFERENCES users(id) ON DELETE SET NULL`.
Indexes: `INDEX(company_document_id)`; `INDEX(user_id)`; `INDEX(created_at)`.

> Append-only; no `updated_at` (immutable event log).

---

### 12. `document_reminder_logs`

Idempotent record of expiry reminders sent per document per threshold, so a given reminder fires at most once.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_document_id | bigint | no | | FK → company_documents |
| threshold | varchar(10) | no | | enum, see CHECK |
| sent_at | timestamptz | no | | reminder dispatch time |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK: `document_reminder_logs_threshold_check CHECK (threshold IN ('90','60','30','expired'))`.
FK: `company_document_id REFERENCES company_documents(id) ON DELETE CASCADE`.
Indexes: `UNIQUE(company_document_id, threshold)` (idempotency).

> Reminder thresholds are **90 / 60 / 30** days before `company_documents.expiry_date`, plus **`expired`** (fired once the document has lapsed).

---

### 13. `verification_requests`

A company's submission for review; drives the admin verification workflow.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies |
| type | varchar(40) | yes | null | requested verification kind |
| status | varchar(20) | no | 'pending' | enum, see CHECK |
| assigned_to | bigint | yes | null | FK → users (admin reviewer) |
| decided_by | bigint | yes | null | FK → users |
| decided_at | timestamptz | yes | null | review time |
| decision_notes | text | yes | null | |
| document_snapshot | jsonb | yes | null | doc ids/statuses captured at submission |
| requested_badges | jsonb | yes | null | badge types the company is requesting |
| created_at | timestamptz | yes | null | submission time |
| updated_at | timestamptz | yes | null | |

CHECK: `verification_requests_status_check CHECK (status IN ('pending','in_review','approved','rejected'))`.
FKs: `company_id REFERENCES companies(id) ON DELETE CASCADE`; `assigned_to`, `decided_by REFERENCES users(id) ON DELETE SET NULL`.
Indexes: `INDEX(company_id)`; `INDEX(status)`; partial `INDEX(status) WHERE status IN ('pending','in_review')` (review queue); `INDEX(assigned_to)`.

> `created_at` is the submission time and `decided_at` the review time — there are no separate `submitted_at`/`reviewed_at` columns.

---

### 14. `verification_badges`

Issued badge artifacts with validity window; multiple badge types per company, at most one `active` per type.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies |
| verification_request_id | bigint | yes | null | FK → verification_requests (origin) |
| badge_type | varchar(40) | no | | enum, see CHECK |
| status | varchar(20) | no | 'active' | enum, see CHECK |
| issued_at | timestamptz | no | now() | verification date |
| valid_until | date | yes | null | "valid until" expiry |
| verified_by | bigint | yes | null | FK → users (issuer/verifier) |
| verification_notes | text | yes | null | internal notes |
| supporting_document_id | bigint | yes | null | FK → company_documents |
| revoked_at | timestamptz | yes | null | |
| revoked_reason | varchar(255) | yes | null | |
| revoked_by | bigint | yes | null | FK → users |
| is_public | boolean | no | true | show badge on public profile |
| reference_code | varchar(40) | no | | public verification reference, UNIQUE |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK:
- `verification_badges_badge_type_check CHECK (badge_type IN ('verified_company','verified_exporter','sigif_registered','legal_timber_supplier','export_ready','cites_approved','sustainability_profile','premium_member'))`
- `verification_badges_status_check CHECK (status IN ('active','revoked','expired'))`

FKs: `company_id REFERENCES companies(id) ON DELETE CASCADE`; `verification_request_id REFERENCES verification_requests(id) ON DELETE SET NULL`; `supporting_document_id REFERENCES company_documents(id) ON DELETE SET NULL`; `verified_by`, `revoked_by REFERENCES users(id) ON DELETE SET NULL`.
Indexes: `UNIQUE(reference_code)`; `INDEX(company_id)`; `INDEX(company_id, badge_type)`; partial `UNIQUE(company_id, badge_type) WHERE status = 'active'` (one active badge per type per company; multiple types allowed); partial `INDEX(valid_until) WHERE status = 'active'` (expiry sweep).

> Display copy is fixed by LEGAL SAFETY rules: "Verified profile", "Verification date", "Valid until", "Documents reviewed by Cameroon Timber Hub based on information submitted by the company", "Buyers should conduct final due diligence before transaction." Never "guaranteed".

---

### 15. `species`

Timber species catalog; powers programmatic SEO species pages.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| slug | varchar(180) | no | | UNIQUE, indexed |
| common_name | varchar(150) | no | | e.g. Sapele |
| scientific_name | varchar(180) | yes | null | e.g. Entandrophragma cylindricum |
| local_names | jsonb | yes | null | array of vernacular names |
| trade_names | jsonb | yes | null | array of market names |
| family | varchar(120) | yes | null | botanical family |
| description | text | yes | null | |
| characteristics | jsonb | yes | null | density, durability, uses, color (structured) |
| is_cites_listed | boolean | no | false | CITES flag |
| cites_appendix | varchar(5) | yes | null | `I`,`II`,`III` |
| image_path | varchar(512) | yes | null | public disk path |
| meta_title | varchar(255) | yes | null | SEO override |
| meta_description | varchar(320) | yes | null | SEO override |
| search_vector | tsvector | yes | null | GENERATED/trigger: common_name(A), scientific_name(A), trade_names(B), description(C) |
| is_published | boolean | no | true | page visibility |
| sort_order | smallint | no | 0 | |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK: `species_cites_appendix_check CHECK (cites_appendix IS NULL OR cites_appendix IN ('I','II','III'))`.
Indexes: `UNIQUE(slug)`; `GIN(search_vector)`; `GIN(common_name gin_trgm_ops)`; `INDEX(is_published)`; `INDEX(is_cites_listed)`.

---

### 16. `rfqs`

Public buyer Request-For-Quote (multi-species possible). No buyer account; verified via email + anti-spam.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| reference_code | varchar(40) | no | | public tracking code, UNIQUE, human-friendly |
| buyer_name | varchar(255) | no | | |
| buyer_company | varchar(255) | yes | null | |
| buyer_email | varchar(255) | no | | |
| buyer_phone | varchar(32) | yes | null | |
| buyer_country_code | char(2) | yes | null | ISO-3166 alpha-2 |
| destination_country_code | char(2) | yes | null | shipping destination |
| incoterm | varchar(10) | yes | null | `FOB`,`CIF`,`CFR`,`EXW`,`DAP`,`other` |
| target_amount | numeric(14,2) | yes | null | |
| target_currency | char(3) | yes | null | XAF/USD/EUR/GBP/CNY |
| deadline | date | yes | null | buyer's required-by date |
| shipping_port | varchar(120) | yes | null | preferred port |
| notes | text | yes | null | free-text requirements |
| status | varchar(20) | no | 'new' | enum, see CHECK |
| visibility | varchar(20) | no | 'public' | enum, see CHECK |
| email_verified_at | timestamptz | yes | null | double opt-in gate |
| ip_address | inet | yes | null | anti-spam |
| source | varchar(40) | yes | null | `species_page`,`company_profile`,`directory`,`contact` |
| spam_score | integer | no | 0 | computed anti-spam score |
| is_spam | boolean | no | false | triage flag |
| attachments | jsonb | yes | null | uploaded reference files |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |
| deleted_at | timestamptz | yes | null | soft delete |

CHECK:
- `rfqs_status_check CHECK (status IN ('new','in_review','approved','rejected','spam','closed'))`
- `rfqs_visibility_check CHECK (visibility IN ('public','private','admin_assisted'))`
- `rfqs_currency_check CHECK (target_currency IS NULL OR target_currency IN ('XAF','USD','EUR','GBP','CNY'))`
- `rfqs_incoterm_check CHECK (incoterm IS NULL OR incoterm IN ('FOB','CIF','CFR','EXW','DAP','other'))`

> Canonical `rfq.status` values are `new, in_review, approved, rejected, spam, closed`. The email-verification gate sets `email_verified_at` before an admin acts. There is NO `specs` jsonb and NO `risk_score`/`risk_flags` columns; buyer country is `buyer_country_code` and the reference is `reference_code`.

Indexes: `UNIQUE(reference_code)`; `INDEX(status)`; partial `INDEX(status) WHERE status = 'new' AND deleted_at IS NULL` (triage queue); partial `INDEX(is_spam) WHERE is_spam = true`; `INDEX(buyer_email)`; `INDEX(created_at)`; partial `INDEX(email_verified_at) WHERE email_verified_at IS NULL` (unverified sweep/cleanup).

---

### 17. `rfq_items`

Line items of an RFQ (per species / spec).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| rfq_id | bigint | no | | FK → rfqs |
| species_id | bigint | yes | null | FK → species (null = free-text) |
| species_text | varchar(180) | yes | null | when species not in catalog |
| form | varchar(20) | yes | null | `logs`,`sawn`,`veneer`,`plywood`,`other` |
| grade | varchar(60) | yes | null | |
| dimensions | varchar(255) | yes | null | requested dimensions |
| quantity | numeric(14,2) | yes | null | |
| unit | varchar(10) | yes | null | `m3`,`ton`,`pcs`,`container` |
| moisture_content | varchar(60) | yes | null | requested moisture spec |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK:
- `rfq_items_form_check CHECK (form IS NULL OR form IN ('logs','sawn','veneer','plywood','other'))`
- `rfq_items_unit_check CHECK (unit IS NULL OR unit IN ('m3','ton','pcs','container'))`

FKs: `rfq_id REFERENCES rfqs(id) ON DELETE CASCADE`; `species_id REFERENCES species(id) ON DELETE SET NULL`.
Indexes: `INDEX(rfq_id)`; `INDEX(species_id)`.

---

### 18. `rfq_company` (routing / lead-per-exporter)

Join of an RFQ to each exporter it was routed to; tracks per-exporter engagement. Alphabetical pivot name.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| rfq_id | bigint | no | | FK → rfqs |
| company_id | bigint | no | | FK → companies |
| status | varchar(20) | no | 'sent' | enum, see CHECK |
| routed_by | bigint | yes | null | FK → users (admin who routed) |
| routed_at | timestamptz | yes | null | |
| viewed_at | timestamptz | yes | null | exporter opened |
| responded_at | timestamptz | yes | null | |
| declined_at | timestamptz | yes | null | |
| response_notes | text | yes | null | exporter's internal note |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK: `rfq_company_status_check CHECK (status IN ('sent','viewed','responded','declined'))`.
FKs: `rfq_id REFERENCES rfqs(id) ON DELETE CASCADE`; `company_id REFERENCES companies(id) ON DELETE CASCADE`; `routed_by REFERENCES users(id) ON DELETE SET NULL`.
Indexes: `UNIQUE(rfq_id, company_id)`; `INDEX(company_id, status)` (exporter lead inbox); `INDEX(rfq_id)`.

---

### 19. `company_inquiries`

Lightweight direct inquiries to a single company (the "contact this company" form), distinct from multi-exporter RFQs. Same email-verify + anti-spam discipline.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies |
| name | varchar(255) | no | | buyer name |
| email | varchar(255) | no | | buyer email |
| phone | varchar(32) | yes | null | |
| message | text | no | | |
| status | varchar(20) | no | 'new' | reuses RFQ triage enum |
| email_verified_at | timestamptz | yes | null | double opt-in |
| ip_address | inet | yes | null | |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK: `company_inquiries_status_check CHECK (status IN ('new','in_review','approved','rejected','spam','closed'))`.
FK: `company_id REFERENCES companies(id) ON DELETE CASCADE`.
Indexes: `INDEX(company_id, status)`; partial `INDEX(status) WHERE status = 'new'`; `INDEX(email)`.

---

### 20. `leads`

Normalized CRM-lite lead per exporter, fed by routed RFQs and inquiries. Powers the exporter lead inbox + light lifecycle.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies (owning exporter) |
| rfq_id | bigint | yes | null | FK → rfqs (origin, if RFQ) |
| rfq_company_id | bigint | yes | null | FK → rfq_company (specific routing row) |
| company_inquiry_id | bigint | yes | null | FK → company_inquiries (origin, if inquiry) |
| source | varchar(20) | no | 'rfq' | `rfq`,`inquiry`,`manual` |
| status | varchar(20) | no | 'new' | enum, see CHECK |
| buyer_name | varchar(255) | yes | null | snapshot |
| buyer_email | varchar(255) | yes | null | snapshot |
| buyer_country_code | char(2) | yes | null | |
| value_amount | numeric(14,2) | yes | null | estimated |
| value_currency | char(3) | yes | null | XAF/USD/EUR/GBP/CNY |
| notes | text | yes | null | exporter notes |
| last_activity_at | timestamptz | yes | null | drives `dormant` sweep |
| assigned_to | bigint | yes | null | FK → users (company staff) |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK:
- `leads_status_check CHECK (status IN ('new','contacted','won','lost','dormant'))`
- `leads_source_check CHECK (source IN ('rfq','inquiry','manual'))`
- `leads_currency_check CHECK (value_currency IS NULL OR value_currency IN ('XAF','USD','EUR','GBP','CNY'))`

FKs: `company_id REFERENCES companies(id) ON DELETE CASCADE`; `rfq_id REFERENCES rfqs(id) ON DELETE SET NULL`; `rfq_company_id REFERENCES rfq_company(id) ON DELETE SET NULL`; `company_inquiry_id REFERENCES company_inquiries(id) ON DELETE SET NULL`; `assigned_to REFERENCES users(id) ON DELETE SET NULL`.
Indexes: `INDEX(company_id, status)` (inbox); `INDEX(last_activity_at)` (dormant sweep); partial `UNIQUE(rfq_company_id) WHERE rfq_company_id IS NOT NULL` (one lead per routing); `INDEX(rfq_id)`.

---

### 21. `plans`

Subscription plans as DATA (no payment gateway). Feature gating reads from here.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| slug | varchar(60) | no | | UNIQUE, e.g. `free`,`verified`,`premium` |
| name | varchar(120) | no | | |
| description | text | yes | null | |
| price_amount | numeric(14,2) | no | 0 | display only |
| price_currency | char(3) | no | 'XAF' | XAF/USD/EUR/GBP/CNY |
| billing_period | varchar(20) | no | 'monthly' | `monthly`,`yearly`,`once` |
| features | jsonb | no | '{}' | feature flags/limits (e.g. `{"max_gallery":10,"rfq_routing":true}`) |
| is_active | boolean | no | true | |
| sort_order | smallint | no | 0 | |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK:
- `plans_currency_check CHECK (price_currency IN ('XAF','USD','EUR','GBP','CNY'))`
- `plans_billing_period_check CHECK (billing_period IN ('monthly','yearly','once'))`

Indexes: `UNIQUE(slug)`; `INDEX(is_active, sort_order)`.

---

### 22. `subscriptions`

Manual admin-assigned plan grants to companies. Authoritative subscription record (`companies.plan_id` is a denormalized convenience mirror).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| company_id | bigint | no | | FK → companies |
| plan_id | bigint | no | | FK → plans |
| status | varchar(20) | no | 'active' | enum, see CHECK |
| starts_at | timestamptz | no | now() | |
| ends_at | timestamptz | yes | null | null = open-ended |
| cancelled_at | timestamptz | yes | null | |
| assigned_by | bigint | yes | null | FK → users (admin) |
| notes | text | yes | null | |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK: `subscriptions_status_check CHECK (status IN ('active','expired','cancelled'))`.
FKs: `company_id REFERENCES companies(id) ON DELETE CASCADE`; `plan_id REFERENCES plans(id) ON DELETE RESTRICT`; `assigned_by REFERENCES users(id) ON DELETE SET NULL`.
Indexes: `INDEX(company_id, status)`; partial `UNIQUE(company_id) WHERE status = 'active'` (one active subscription per company); partial `INDEX(ends_at) WHERE status = 'active'` (expiry sweep).

---

### 23. `pages`

CMS-style editorial / programmatic-SEO landing pages (e.g. "Buy Sapele from Cameroon"), distinct from species pages.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| slug | varchar(200) | no | | UNIQUE, indexed |
| title | varchar(255) | no | | |
| h1 | varchar(255) | yes | null | page headline |
| meta_description | varchar(320) | yes | null | SEO |
| data | jsonb | yes | null | structured body blocks for programmatic templates |
| schema_json | jsonb | yes | null | JSON-LD structured data |
| canonical_url | varchar(512) | yes | null | canonical override (derives from `APP_URL` when null) |
| template | varchar(30) | no | 'static' | `static`,`landing`,`programmatic`,`legal` |
| is_published | boolean | no | false | |
| search_vector | tsvector | yes | null | GENERATED/trigger: title(A), h1(A), data(B) |
| created_by | bigint | yes | null | FK → users |
| created_at | timestamptz | yes | null | |
| updated_at | timestamptz | yes | null | |

CHECK: `pages_template_check CHECK (template IN ('static','landing','programmatic','legal'))`.
FK: `created_by REFERENCES users(id) ON DELETE SET NULL`.
Indexes: `UNIQUE(slug)`; `GIN(search_vector)`; `INDEX(is_published)`; `INDEX(template)`.

> Body content lives in the `data` jsonb column (there is NO `content`/`body` column). `canonical_url` is a nullable override; when null the canonical derives from `APP_URL`.

---

### 24. `suspicious_events`

Security/anti-abuse signals (rate-limit hits, honeypot trips, RFQ bursts, repeated failed logins, duplicate submissions). Feeds admin security baseline.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | bigint identity | no | | PK |
| event_type | varchar(40) | no | | enum, see CHECK |
| severity | varchar(10) | no | 'low' | `low`,`medium`,`high` |
| subject_type | varchar(255) | yes | null | polymorphic (e.g. rfqs, company_inquiries) |
| subject_id | bigint | yes | null | polymorphic id |
| user_id | bigint | yes | null | FK → users |
| ip_address | inet | yes | null | |
| context | jsonb | yes | null | holds buyer_email, payload, request details, scores, headers |
| created_at | timestamptz | yes | null | |

CHECK:
- `suspicious_events_event_type_check CHECK (event_type IN ('honeypot_triggered','rate_limited','rapid_rfq_burst','repeated_failed_login','suspicious_rfq','duplicate_submission'))`
- `suspicious_events_severity_check CHECK (severity IN ('low','medium','high'))`

FK: `user_id REFERENCES users(id) ON DELETE SET NULL`.
Indexes: `INDEX(event_type)`; `INDEX(ip_address)`; `INDEX(severity, created_at)`; `INDEX(subject_type, subject_id)`.

> Append-only event log; no `updated_at`. Uses `event_type` and `context` (NOT `type`/`payload`/a top-level `buyer_email` — `buyer_email` and any raw payload live inside `context`).

---

### 25–29. Platform / package-managed tables

These are created by Laravel or first-party packages; listed for completeness as part of the single source of truth.

#### 25. spatie/laravel-permission

- **`roles`** — `id`, `name varchar`, `guard_name varchar`, `created_at`, `updated_at`; `UNIQUE(name, guard_name)`. Single default `web` guard. Platform staff role names: `super_admin`, `admin`, `verification_officer`, `content_manager` (seedable-but-unused-in-MVP: `sales_officer`, `finance_officer`, `support_officer`). spatie roles are for platform staff only; company-side authorization uses `company_user.role`.
- **`permissions`** — `id`, `name varchar`, `guard_name varchar`, timestamps; `UNIQUE(name, guard_name)`. Permission slugs: `companies.view, companies.manage, companies.suspend, documents.review, verification.review, badges.issue, badges.revoke, species.manage, rfqs.triage, rfqs.route, pages.manage, plans.manage, users.manage, audit.view`.
- **`model_has_roles`** — `role_id` FK, `model_type varchar`, `model_id bigint`; PK `(role_id, model_id, model_type)`; `INDEX(model_id, model_type)`.
- **`model_has_permissions`** — `permission_id` FK, `model_type`, `model_id`; PK `(permission_id, model_id, model_type)`.
- **`role_has_permissions`** — `permission_id` FK, `role_id` FK; PK `(permission_id, role_id)`; both FKs `ON DELETE CASCADE`.

#### 26. spatie/laravel-activitylog

- **`activity_log`** — standard spatie schema: `id`, `log_name varchar null`, `description text`, `subject_type varchar null`, `subject_id bigint null`, `causer_type varchar null`, `causer_id bigint null`, `properties jsonb null`, `event varchar null`, `batch_uuid uuid null`, `created_at`, `updated_at`; `INDEX(log_name)`, `INDEX(subject_type, subject_id)`, `INDEX(causer_type, causer_id)`. Records verification decisions, badge issue/revoke, document moderation, plan assignment, RFQ triage.

> IP address and user agent are written INTO the `properties` JSONB by a global activity tap/middleware. There are NO extra `ip_address`/`user_agent` columns on `activity_log`.

#### 27. Laravel notifications

- **`notifications`** — `id uuid` PK, `type varchar`, `notifiable_type varchar`, `notifiable_id bigint`, `data jsonb`, `read_at timestamptz null`, `created_at`, `updated_at`; `INDEX(notifiable_type, notifiable_id)`. Used for exporter lead alerts, document-expiry reminders, verification outcomes.

#### 28. Queue / jobs

- **`jobs`** — `id`, `queue varchar` (indexed), `payload longtext`, `attempts tinyint`, `reserved_at int null`, `available_at int`, `created_at int`. (Redis is the primary queue driver; this table backs the `database` fallback / batchable jobs.)
- **`job_batches`** — `id varchar` PK, `name`, `total_jobs`, `pending_jobs`, `failed_jobs`, `failed_job_ids longtext`, `options mediumtext null`, `cancelled_at int null`, `created_at int`, `finished_at int null`.
- **`failed_jobs`** — `id`, `uuid uuid UNIQUE`, `connection text`, `queue text`, `payload longtext`, `exception longtext`, `failed_at timestamptz default now()`.

#### 29. Cache & sessions

- **`cache`** — `key varchar` PK, `value mediumtext`, `expiration int`. **`cache_locks`** — `key varchar` PK, `owner varchar`, `expiration int`. (Redis is primary; these back the `database` store fallback.)
- **`sessions`** — `id varchar` PK, `user_id bigint null` (indexed), `ip_address varchar(45) null`, `user_agent text null`, `payload longtext`, `last_activity int` (indexed). (Redis is primary session store; table provided for fallback/audit.)
- **`password_reset_tokens`** — `email varchar` PK, `token varchar`, `created_at timestamptz null`.

---

### 30. Eloquent relationships summary

```
User
  belongsToMany(Company) via company_user  (withPivot role, is_primary)
  hasMany(CompanyDocument, 'uploaded_by') / reviewedDocuments('reviewed_by')
  hasRoles()/hasPermissions()              // spatie HasRoles trait (web guard)
  morphMany(Activity, causer)              // activitylog

Company  (SoftDeletes)
  getNameAttribute(): trade_name ?: legal_name    // display accessor (no `name` column)
  belongsToMany(User) via company_user (withPivot role, is_primary)
  hasMany(CompanyContact, CompanyGallery, CompanyExportMarket, CompanySocialLink)
  belongsToMany(Species) via company_species (withPivot form, grade, min_order_m3, price_amount, price_currency, is_primary)
  hasMany(CompanyDocument)                 // SoftDeletes
  hasMany(VerificationRequest)
  hasMany(VerificationBadge)
  hasMany(VerificationBadge)->where status=active   // activeBadges (one per badge_type)
  hasMany(RfqCompany)  +  belongsToMany(Rfq) via rfq_company (withPivot status, timestamps)
  hasMany(CompanyInquiry)
  hasMany(Lead)
  hasMany(Subscription) + hasOne activeSubscription (status=active)
  belongsTo(Plan)                          // denormalized companies.plan_id
  belongsTo(User,'created_by')

CompanyDocument  (SoftDeletes)
  belongsTo(Company), belongsTo(DocumentType)
  belongsTo(User,'reviewed_by') / belongsTo(User,'uploaded_by')
  hasMany(DocumentAccessLog)
  hasMany(DocumentReminderLog)

DocumentType        hasMany(CompanyDocument)
DocumentAccessLog   belongsTo(CompanyDocument), belongsTo(User)
DocumentReminderLog belongsTo(CompanyDocument)

VerificationRequest
  belongsTo(Company); belongsTo(User,'assigned_to'|'decided_by')
  hasMany(VerificationBadge)
VerificationBadge
  belongsTo(Company), belongsTo(VerificationRequest)
  belongsTo(CompanyDocument,'supporting_document_id')
  belongsTo(User,'verified_by'|'revoked_by')

Species
  belongsToMany(Company) via company_species
  hasMany(RfqItem)

Rfq  (SoftDeletes)
  hasMany(RfqItem)
  hasMany(RfqCompany) + belongsToMany(Company) via rfq_company
  hasMany(Lead)
RfqItem            belongsTo(Rfq), belongsTo(Species)
RfqCompany         belongsTo(Rfq), belongsTo(Company), belongsTo(User,'routed_by'); hasOne(Lead)
CompanyInquiry     belongsTo(Company); hasOne(Lead)

Lead
  belongsTo(Company); belongsTo(Rfq); belongsTo(RfqCompany); belongsTo(CompanyInquiry)
  belongsTo(User,'assigned_to')

Plan               hasMany(Subscription); hasMany(Company)   // via denormalized plan_id
Subscription       belongsTo(Company), belongsTo(Plan), belongsTo(User,'assigned_by')

Page               belongsTo(User,'created_by')
SuspiciousEvent    belongsTo(User); morphTo(subject)         // subject_type/subject_id
```

**Cross-cutting notes**

- Every status/enum column listed above is backed by a PHP `enum` cast and the named DB `CHECK` constraint; the canonical value sets are exactly those in the brief and must not drift.
- Soft deletes (`deleted_at`) apply only to `companies`, `company_documents`, `rfqs`, `users`; all partial "active" indexes on those tables include `WHERE deleted_at IS NULL`.
- All money is `numeric(14,2)` amount + `char(3)` currency constrained to `XAF, USD, EUR, GBP, CNY`.
- `tsvector` columns (`companies`, `species`, `pages`) are the Postgres full-text surface (GIN-indexed); `pg_trgm` GIN indexes support fuzzy name typeahead. Laravel Scout (database driver) layers Eloquent filters on top.
- Two filesystem disks: private **`documents`** (only `company_documents`, signed-URL access) and **`public`** (crawlable images — company logo/cover, gallery, species, page OG images).
- Append-only logs (`document_access_logs`, `suspicious_events`, `activity_log` events) are immutable; the first two intentionally omit `updated_at`.

---

## RBAC & Permissions

This section specifies authorization for Cameroon Timber Hub using `spatie/laravel-permission`. It defines the two authenticated surfaces (platform staff and company users), the granular permission catalog, the platform role matrix, the company-side role model, and the Eloquent Policies enforcing company-scoped data isolation. There is exactly **one** auth guard (`web`); all spatie roles and permissions live under the default guard with no per-guard duplication. The two Filament panels are distinguished by `canAccessPanel()`, and all `/dashboard` data access is additionally scoped to the acting user's company via `company_user` membership.

### Guards, Panels & Identity

A **single** Laravel auth guard (`web`) over the `users` table backs both Filament panels and the one custom TALL public site. There are no separate guards, no `authGuard('admin')`/`authGuard('exporter')`, and no separate panel cookies. The public site is unauthenticated except for the public RFQ/inquiry flows (no buyer accounts in MVP).

| Surface | Panel / Stack | Guard | `users` who may enter | Role source |
| --- | --- | --- | --- | --- |
| Platform admin | Filament `admin` panel at `/admin` | `web` (single guard) | Staff users (have a platform spatie role) | spatie role on the default `web` guard |
| Exporter dashboard | Filament `dashboard` panel at `/dashboard` | `web` (single guard) | Users with a row in `company_user` | `company_user.role` (owner/manager/member) |
| Public website | Custom Blade + Livewire (TALL) | none | Anyone (anonymous) | n/a |

Both panels authenticate the same `users` table through the **same single `web` guard**. The distinction between "staff" and "company member" is by **role assignment and membership**, not by separate guards. Using one guard keeps `spatie/laravel-permission` role/permission lookups simple and avoids duplicating the permissions table per guard — all spatie roles and permissions are registered under the default guard only. Configuration:

- `config/permission.php`: single guard `web`. All permissions and platform roles are registered under the default `web` guard; there is no per-guard duplication of the roles/permissions tables.
- Panel access is gated by `canAccessPanel(Panel $panel): bool` on the `User` model:

```php
public function canAccessPanel(Panel $panel): bool
{
    return match ($panel->getId()) {
        'admin'     => $this->isPlatformStaff(),      // has any platform role → /admin requires platform staff role
        'dashboard' => $this->companies()->exists(),  // has company_user membership → /dashboard requires company membership
        default     => false,
    };
}

public function isPlatformStaff(): bool
{
    return $this->hasAnyRole([
        'super_admin', 'admin', 'verification_officer', 'content_manager',
        'sales_officer', 'finance_officer', 'support_officer',
    ]);
}
```

`/admin` requires a platform staff role (`super_admin`/`admin`/`verification_officer`/`content_manager`); `/dashboard` requires company membership (a `company_user` row). The seedable-but-unused roles (`sales_officer`/`finance_officer`/`support_officer`) also count as staff for panel access.

#### Identity model (staff vs company member)

- A **platform staff** user is any `users` row that has been assigned one or more *platform* spatie roles (see matrix below). Staff are created and role-assigned only by `super_admin` / `admin` from `/admin`. Staff typically have no `company_user` rows.
- A **company member** user is any `users` row with at least one `company_user` row linking them to a company. Company-side authorization is governed **entirely by `company_user.role`** (`owner`/`manager`/`member`), **not** by any spatie role — there is no "Company Owner" spatie role. spatie roles are for platform staff only.
- The two are not mutually exclusive at the data layer, but in practice are kept separate. `canAccessPanel` resolves each panel independently, so a user with both a platform role and a `company_user` row could reach both panels; seeders and admin UX discourage this mixing.
- **For MVP a user belongs to exactly ONE company** — a single-company dashboard with **no Filament tenancy**. Multi-company membership is V2.
- `users` relationships used throughout this section:

```php
// User model
public function companies(): BelongsToMany   // via company_user pivot
{
    return $this->belongsToMany(Company::class)
        ->using(CompanyUser::class)
        ->withPivot(['role', 'is_primary'])
        ->withTimestamps();
}
```

The `company_user` pivot (alphabetical singular_singular) carries: `company_id`, `user_id`, `role` (enum `owner, manager, member` with a DB CHECK constraint), `is_primary` (bool, marks the owner/primary contact), timestamps. Because MVP membership is single-company, each member's company is resolved from their single `company_user` row.

### Permission Catalog

Permissions are fine-grained verbs over domain resources, registered under the default `web` guard and seeded by `RolesAndPermissionsSeeder`. Naming convention: `{resource}.{action}`, dot-delimited, lower snake within segments. Filament resource pages and Policies both consult these permissions; Livewire admin actions (if any) consult the same names. The catalog below is the **exact** canonical permission slug set — no more, no fewer.

| Permission | Protects | Notes |
| --- | --- | --- |
| `companies.view` | View company profiles in `/admin`, incl. non-public statuses | Read-only access to `companies` |
| `companies.manage` | Create/edit company records, change status among `draft/pending/verified/rejected/archived` | Excludes `suspended` (see below) |
| `companies.suspend` | Transition `company.status` to/from `suspended` | **`super_admin` only** |
| `documents.review` | View `company_documents`, stream private files via signed URLs, and set `company_document.status` to `approved`/`rejected`/`needs_correction` | Verification Officer core action; honors `company_document.visibility` |
| `verification.review` | Open/advance `verification_request` through `pending → in_review → approved/rejected` | Verification workflow |
| `badges.issue` | Create/activate a `verification_badge` (`status = active`, set `issued_at`/`valid_until`) | Gated also by approved verification |
| `badges.revoke` | Set `verification_badge.status = revoked` | Verification Officer + Admin |
| `species.manage` | CRUD `species` catalog + species SEO fields/slugs | Content Manager core action |
| `rfqs.triage` | Set `rfq.status` (`new/in_review/approved/rejected/spam/closed`) | Triage queue |
| `rfqs.route` | Create `rfq_company` rows routing an RFQ to exporters; manage `rfq_company.status` | Distribution to exporters |
| `pages.manage` | CRUD CMS `pages` (slugs, SEO meta, programmatic SEO content) | Content Manager core action |
| `plans.manage` | CRUD subscription plans-as-data **and** manually assign/revoke `subscriptions` | **`super_admin` only** |
| `users.manage` | Create staff users, assign/revoke platform roles, manage company memberships | **`super_admin` only** |
| `audit.view` | Read the activity log (`spatie/laravel-activitylog`) in `/admin` | No write/delete of audit entries |

> Restricted-to-`super_admin` permissions (`companies.suspend`, `plans.manage`, `users.manage`) are never granted to lower roles by the seeder and are enforced again in Policies as a defense-in-depth check.

Company-side dashboard actions are **not** governed by these platform permissions. Dashboard authorization is governed by `company_user.role` plus per-record company scoping (see Policies). This keeps the platform permission catalog exclusively about staff capabilities.

### Platform Roles & Role → Permission Matrix

Four platform roles ship in MVP: `super_admin`, `admin`, `verification_officer`, `content_manager`. Three further roles — `sales_officer`, `finance_officer`, `support_officer` — are **seedable but unused in MVP**: they are reserved for V2 so org structure exists without granting capability now. They appear in `isPlatformStaff()` and can be assigned, but carry no MVP permissions.

Legend: ✔ granted · — not granted · (SA) `super_admin`-exclusive.

| Permission | `super_admin` | `admin` | `verification_officer` | `content_manager` | `sales_officer`* | `finance_officer`* | `support_officer`* |
| --- | :--: | :--: | :--: | :--: | :--: | :--: | :--: |
| `companies.view` | ✔ | ✔ | ✔ | ✔ | — | — | — |
| `companies.manage` | ✔ | ✔ | — | — | — | — | — |
| `companies.suspend` (SA) | ✔ | — | — | — | — | — | — |
| `documents.review` | ✔ | ✔ | ✔ | — | — | — | — |
| `verification.review` | ✔ | ✔ | ✔ | — | — | — | — |
| `badges.issue` | ✔ | ✔ | ✔ | — | — | — | — |
| `badges.revoke` | ✔ | ✔ | ✔ | — | — | — | — |
| `species.manage` | ✔ | ✔ | — | ✔ | — | — | — |
| `rfqs.triage` | ✔ | ✔ | — | — | — | — | — |
| `rfqs.route` | ✔ | ✔ | — | — | — | — | — |
| `pages.manage` | ✔ | ✔ | — | ✔ | — | — | — |
| `plans.manage` (SA) | ✔ | — | — | — | — | — | — |
| `users.manage` (SA) | ✔ | — | — | — | — | — | — |
| `audit.view` | ✔ | ✔ | ✔ | — | — | — | — |

\* `sales_officer` / `finance_officer` / `support_officer` are V2 placeholders: assignable, counted as staff, no MVP permissions.

The matrix above is verbatim from the canonical decisions:

- **`super_admin`**: ALL permissions.
- **`admin`**: `companies.view`, `companies.manage`, `documents.review`, `verification.review`, `badges.issue`, `badges.revoke`, `species.manage`, `rfqs.triage`, `rfqs.route`, `pages.manage`, `audit.view`. (NOT `companies.suspend`, `plans.manage`, `users.manage`.)
- **`verification_officer`**: `companies.view`, `documents.review`, `verification.review`, `badges.issue`, `badges.revoke`, `audit.view`.
- **`content_manager`**: `companies.view`, `species.manage`, `pages.manage`.

Implementation notes:

- **`super_admin`** is additionally short-circuited via `Gate::before` to bypass individual permission checks, ensuring it always holds the SA-exclusive capabilities even if seed drift occurs:

```php
Gate::before(fn (User $user, string $ability) =>
    $user->hasRole('super_admin') ? true : null);
```

- Roles and permissions are idempotently seeded under the default `web` guard; the seeder is the source of truth and is re-runnable. New permissions added later must be appended to the seeder and the matrix above.
- Staff role assignment is performed only through `/admin` by holders of `users.manage` (`super_admin`), and every assignment/revocation is recorded by `spatie/laravel-activitylog`.

### Company-Side Roles

Company users operate only in `/dashboard`. Company-side authorization is governed by the **`company_user.role`** value, **not** by any spatie role. `company_user.role` is a string column with a PHP enum cast and a DB CHECK constraint, carrying the canonical company-side role values:

```
company_user.role: owner, manager, member
```

- **`owner`** — full authority over *their own* company's data: edit the company profile (fields not locked by verification state), upload/replace/withdraw `company_documents`, submit a `verification_request`, view issued badges (read-only; badges are issued/revoked by staff), view and respond to their `leads`/routed `rfq_company` rows (light lead inbox), and view their own `subscription` and plan entitlements (read-only; assignment is manual by staff).
- **`manager`** — limited write within the same company (e.g. profile + documents), no ownership-transfer or membership management.
- **`member`** — read-only access to their company's data.

In MVP a user belongs to exactly one company (single-company dashboard, no Filament tenancy), and the company-side role is read from that single `company_user` row. There is **no** "Company Owner" spatie role; the real authorization gate is **company scoping** in the Policies, combined with the `company_user.role` value, not any platform role.

### Policies & Company Scoping

Eloquent Policies enforce authorization at the model layer for both panels and any Livewire/controller path, so rules hold regardless of UI surface. Policies are registered in `AuthServiceProvider` (or auto-discovered) and combine: (1) platform permission checks for staff, and (2) hard company-scoping for company users (whose rights derive from `company_user.role`).

Policies to implement:

| Policy | Model | Core abilities |
| --- | --- | --- |
| `CompanyPolicy` | `Company` | `viewAny`, `view`, `create`, `update`, `suspend`, `delete` (soft) |
| `CompanyDocumentPolicy` | `CompanyDocument` | `viewAny`, `view`, `create`, `update`, `review`, `delete` (soft) |
| `VerificationRequestPolicy` | `VerificationRequest` | `viewAny`, `view`, `create`, `review` |
| `VerificationBadgePolicy` | `VerificationBadge` | `viewAny`, `view`, `issue`, `revoke` |
| `SpeciesPolicy` | `Species` | `viewAny`, `view`, `create`, `update`, `delete` |
| `PagePolicy` | `Page` | `viewAny`, `view`, `create`, `update`, `delete` |
| `PlanPolicy` | `Plan` | `viewAny`, `view`, `create`, `update`, `delete` |
| `SubscriptionPolicy` | `Subscription` | `viewAny`, `view`, `assign`, `revoke` |
| `RfqPolicy` | `Rfq` | `viewAny`, `view`, `triage`, `route` |
| `RfqCompanyPolicy` | `RfqCompany` | `view`, `respond` (company side), `update` (staff routing) |
| `LeadPolicy` | `Lead` | `viewAny`, `view`, `update` (company side: respond) |
| `ActivityLogPolicy` | `Activity` | `viewAny`, `view` (read-only; no `create`/`delete`) |

#### Company-scoping rule (the invariant)

> A company user in `/dashboard` may read or write **only** records belonging to the single company they are a member of via `company_user`. They can never enumerate, view, or mutate another company's data — a company user only ever sees their own company's data in `/dashboard`.

This is enforced in two layers:

1. **Query scoping** — the `dashboard` panel applies a global owned-company filter so list/table queries only ever return the acting user's company-owned rows. A reusable `BelongsToCompany` trait + `OwnedByCompanyScope` (or per-Resource `getEloquentQuery()` override) constrains every dashboard query. Because MVP membership is single-company, this resolves to one company id:

```php
// In each dashboard Filament Resource
protected static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery()
        ->whereIn('company_id', auth()->user()->companies()->pluck('companies.id'));
}
```

2. **Policy gate** — every Policy method re-checks ownership for company users via a shared helper, so even a guessed ID or a direct controller call is denied:

```php
// Shared trait used by company-scoped policies
protected function ownsCompany(User $user, int $companyId): bool
{
    return $user->companies()->whereKey($companyId)->exists();
}
```

#### Representative policy logic

`CompanyDocumentPolicy::view` — staff path via permission, company path via ownership:

```php
public function view(User $user, CompanyDocument $doc): bool
{
    // Platform staff
    if ($user->can('documents.review')) {
        return true; // visibility further filtered when streaming the file
    }
    // Company user: only their own company's documents
    return $this->ownsCompany($user, $doc->company_id);
}

public function review(User $user, CompanyDocument $doc): bool
{
    return $user->can('documents.review'); // staff-only; company users never review
}
```

`CompanyPolicy::suspend` — SA-exclusive, double-guarded:

```php
public function suspend(User $user, Company $company): bool
{
    return $user->hasRole('super_admin') && $user->can('companies.suspend');
}
```

`CompanyPolicy::update` — staff manage vs. company-side `owner`/`manager` edits own profile:

```php
public function update(User $user, Company $company): bool
{
    if ($user->can('companies.manage')) {
        return true; // staff
    }
    // Company user: must own the company AND hold a write-capable company_user.role
    return $this->ownsCompany($user, $company->getKey())
        && $this->companyRole($user, $company->getKey()) !== 'member';
}
```

`SubscriptionPolicy` — staff assign/revoke; company sees own read-only:

```php
public function assign(User $user, Subscription $sub): bool
{
    return $user->can('plans.manage'); // super_admin only
}

public function view(User $user, Subscription $sub): bool
{
    return $user->can('plans.manage') || $this->ownsCompany($user, $sub->company_id);
}
```

#### Cross-cutting rules

- **Document visibility vs. authorization**: passing `CompanyDocumentPolicy::view` authorizes *access to the record*; the actual file stream additionally honors `company_document.visibility` (`private`, `admin_only`, `buyer_visible`, `public`) via signed URLs from the `DocumentService` over the private `documents` disk. A company user (`owner`/`manager`/`member`) may always view their own company's documents regardless of `visibility`; `admin_only` documents are never streamed to company users.
- **Public site** reads only published/`verified`/`public`-visibility data through dedicated read models or query scopes and never invokes the staff/company Policies for authorization (anonymous traffic), though it reuses the same visibility constraints.
- **Soft-deletes**: `restore`/`forceDelete` abilities on soft-deletable models (`companies`, `company_documents`, `rfqs`, `users`) are restricted to `super_admin` (via `Gate::before`) and `admin` where `companies.manage` applies; company users have no restore/force-delete ability.
- **Defense in depth**: SA-exclusive permissions are enforced by (a) seeder never granting them to non-SA roles, (b) `Gate::before` for `super_admin`, and (c) explicit `hasRole('super_admin')` checks in the relevant Policy methods.

---

## Directory & Species Domain

This section specifies the runtime behavior and UI for the Directory & Species domain: the company lifecycle and public-visibility gating, the exporter `/dashboard` Filament panel, the admin `/admin` Filament resources for companies and species, the public-facing directory and species pages, and the cross-cutting concerns of slugs, image handling, and empty states. All table and column references follow the canonical DATA MODEL; no columns are redefined here.

### 1. Company Lifecycle & Public Visibility Gating

#### 1.1 Lifecycle states

`companies.status` is a string column cast to a PHP enum (`CompanyStatus`) with the canonical values:

| Status | Meaning | Set by |
| --- | --- | --- |
| `draft` | Created during onboarding; profile incomplete or not yet submitted. | System (on exporter registration) |
| `pending` | Submitted by exporter for review; awaiting admin action. | Exporter (submit) / system |
| `verified` | Reviewed and approved by an admin; eligible for public listing. | Admin |
| `suspended` | Temporarily hidden by an admin (policy, expired docs, dispute). | Admin |
| `rejected` | Reviewed and declined. | Admin |
| `archived` | Soft-retired; never shown publicly, excluded from dashboards. | Admin |

Canonical state transitions (enforced in `CompanyStatusService`, the single mutation point; Filament actions and onboarding both call it):

```
draft     -> pending   (exporter submits for review)
pending   -> verified  (admin approves)
pending   -> rejected  (admin declines, reason required)
pending   -> needs work: pending -> draft (admin returns for correction, reason required)
verified  -> suspended (admin suspends, reason required)
suspended -> verified  (admin reinstates, reason required)
verified  -> archived  (admin archives)
rejected  -> pending   (exporter re-submits after edits)
any non-archived -> archived (admin)
```

Illegal transitions throw a domain exception and are rejected by the UI (action not offered). Every transition writes an activity-log entry (spatie/laravel-activitylog) capturing actor, from-status, to-status, and the reason text. `companies` uses soft deletes; archiving sets `status = archived` but does NOT soft-delete — `archived` is a recoverable business state, while `deleted_at` is reserved for hard removal/GDPR-style erasure.

#### 1.2 Profile completeness

A company is **publicly listable only when it is both `verified` AND profile-complete**. Completeness is a computed gate (not a stored truth source), evaluated by `CompanyCompletenessService::score(Company): CompletenessResult`, returning a 0–100 percentage and a list of missing requirements. Required fields for completeness:

- A display name (via the `name` accessor, which returns `trade_name ?: legal_name`), `slug`, `description` (non-empty, min length enforced). `legal_name` is required; `trade_name` is optional.
- `region` populated (drives directory region filter).
- At least one contact entry (a row in the `company_contacts` relation).
- At least one species association (`company_species` pivot).
- A logo image present (`logo_path`).
- At least one `verification_badge` with `status = active` and not expired (`valid_until` null or in the future).

Completeness percentage is surfaced in both Filament panels (progress indicator + checklist). The percentage itself does not gate visibility; the explicit boolean requirements above do. This avoids a "98%-but-no-logo" company leaking onto public pages.

#### 1.3 The public-visibility rule (single source of truth)

All public reads go through a single Eloquent query scope, `Company::scopePubliclyVisible()`, used by every public page, the directory, sitemap generation, and structured data. No public surface may query `companies` without it.

```php
// Conceptual definition — the ONLY gate for public exposure
public function scopePubliclyVisible(Builder $q): Builder
{
    return $q->where('status', CompanyStatus::Verified)
             ->whereNotNull('logo_path')
             ->whereNotNull('description')
             ->whereNotNull('region')
             ->whereHas('species')          // >=1 company_species row
             ->whereHas('contacts')         // >=1 company_contacts row
             ->whereHas('verificationBadges', fn ($b) =>
                 $b->where('status', BadgeStatus::Active)
                   ->where(fn ($e) => $e->whereNull('valid_until')
                                        ->orWhere('valid_until', '>', now())));
}
```

The contact gate is a relation existence check (`whereHas('contacts')` against the `company_contacts` table); there is no JSONB contacts column on `companies`. Export markets are likewise a relation (`company_export_markets`), not a JSONB column.

Consequences:
- `draft`, `pending`, `rejected`, `suspended`, `archived` companies are **never** visible publicly, return 404 on `/companies/{slug}`, and are excluded from the directory, sitemap, and any species "exporters of this species" list.
- A company that loses its last active badge (revoked or expired) silently drops out of public listings on the next request — no manual republish needed.
- The scope is covered by Pest feature tests asserting each excluded status returns 404 and is absent from the directory query.

A separate `scopeDashboardOwned(User $user)` scope (status-agnostic, scoped to the user's own company via company membership) governs what the exporter sees in `/dashboard`; an admin sees all statuses in `/admin`.

### 2. Exporter Dashboard (`/dashboard` Filament panel)

A second Filament 3 panel registered at `/dashboard` with its own panel ID (`dashboard`). It runs on the SINGLE shared `web` guard over the `users` table (no separate guard, no Filament tenancy). Access is granted via `canAccessPanel()` to any user with **company membership** — i.e. a `company_user` pivot row linking them to exactly one company. There is no spatie "exporter" role; dashboard access is membership-based, and company-side capabilities are governed by `company_user.role` (`owner`/`manager`/`member`). Users with no company linkage are routed to the onboarding flow; users linked to an `archived` company see a read-only notice.

For MVP a user belongs to exactly ONE company (single-company dashboard); multi-company is a V2 concern. Every resource and query in this panel is hard-scoped to the authenticated user's company. This is enforced centrally by overriding `getEloquentQuery()` on each resource to apply `scopeDashboardOwned(auth()->user())`, plus a panel-level ownership check so a manipulated record ID cannot reach another company's row (returns 403).

Company-side authorization within the panel keys off `company_user.role`: `owner` = full company access; `manager` = limited; `member` = read. (`accountant`/`viewer` are V2.) These are NOT spatie roles — spatie roles are reserved for platform staff in `/admin`.

#### 2.1 CompanyProfile editor (single-record resource)

The centerpiece is a **single-record editor**: there is no list/index for the exporter's own company. The resource resolves the one owned company (via company membership) and opens directly into an edit form (Filament's single-record page pattern). If the company is `draft`/`pending`, the editor is fully editable; if `verified`, edits to a defined subset of fields move the company back to `pending` and flag affected sections for re-review (see 2.1.2).

##### 2.1.1 Form schema

Organized as Filament form tabs/sections. Fields map to canonical `companies` columns (`legal_name`, `trade_name`, `slug`, `description`, `region`, `logo_path`, `cover_path`), plus the `company_contacts`, `company_export_markets`, and `company_species` relations and the gallery relation. Display name throughout the UI comes from the `name` accessor (`trade_name ?: legal_name`); there is no `name` column:

- **Identity** — `legal_name` (text, required) and `trade_name` (text, optional); the public display name is derived by the `name` accessor. `slug` (read-only display, see §6 immutability); `description` (rich/long text, required, min length, plain-text length counter for SEO meta derivation).
- **Branding** — `logo_path` (square image upload, see §5); `cover_path` (wide banner image upload). Both stored on the **public** disk.
- **Location** — `region` (select from the canonical Cameroon regions list, required); free-text city/address fields as defined in the data model; optional lat/long if present.
- **Contacts** — a Filament `Repeater` bound to the `company_contacts` relation (a related-records repeater, NOT a JSONB column). Each row: `type` (enum: `phone`, `email`, `whatsapp`, `website`, `office`), `label`, `value`, `is_primary` (only one primary per type, validated). At least one contact required for completeness. Rows are persisted to `company_contacts`; validated on save.
- **Export markets** — a multi-select bound to the `company_export_markets` relation (ISO region/country codes or a curated market list, e.g. EU, China, USA, MENA, Other), stored as related rows (NOT a JSONB column). Drives the directory export-market filter.
- **Species handled** — a multi-select bound to the `company_species` pivot (options sourced from the `species` table, searchable). Selecting/deselecting syncs the pivot. At least one required for completeness.
- **Gallery** — a `Repeater` or multi-file upload bound to the company gallery relation (project/product photos), each with an optional caption and sort order; images stored on the **public** disk per §5.

A persistent **Completeness checklist** widget on the page renders `CompletenessResult`: percentage bar plus a list of unmet requirements with deep links to the relevant tab ("Add a logo", "Select at least one species"). A **Verification status** widget shows current `company.status`, active badge (with "Valid until" date from `valid_until`), and a **Submit for review** action (visible only when `draft` or `rejected` and completeness requirements are met) that transitions `draft/rejected -> pending` via `CompanyStatusService`.

##### 2.1.2 Edit-after-verified behavior

To prevent a verified company from silently altering trust-bearing content, edits are partitioned:

- **Trust-affecting fields** (`legal_name`, `trade_name`, `description`, `region`, contacts, species, export markets): saving changes to these while `verified` triggers `verified -> pending`, records the changed fields in the activity log, and shows a notice: "Your profile changes need re-review. Your verified badge remains valid until review completes." The badge is NOT auto-revoked; the company remains publicly visible during re-review unless an admin acts.
- **Non-trust fields** (gallery images, cover image, contact `label` cosmetic edits): saved in place, no status change.

The partition list lives in `CompanyProfile`'s `$reviewTriggeringFields` and is unit-tested.

#### 2.2 Other dashboard resources (in this domain)

- **Documents** (cross-references the Verification domain): list/upload of `company_documents` scoped to the owned company, with per-document `status` and reviewer feedback shown read-only. Documents live on the private `documents` disk (never public). (Detailed behavior owned by the Verification section; listed here for panel completeness.)
- **Leads inbox** (cross-references the RFQ/Lead domain): read-light list of `rfq_company` / `lead` rows routed to this company. (Owned by the RFQ section.)
- The dashboard's species interaction is limited to the multi-select above; exporters cannot create/edit `species` records (admin-only).

### 3. Admin Panel (`/admin` Filament resources)

The primary Filament panel at `/admin`, panel ID `admin`, also on the single `web` guard. Access via `canAccessPanel()` requires a platform staff spatie role (`super_admin`/`admin`/`verification_officer`/`content_manager`). Relevant resources for this domain:

#### 3.1 CompanyResource

- **List** — columns: logo thumbnail, display name (the `name` accessor = `trade_name ?: legal_name`), `region`, `status` (badge-colored: draft=gray, pending=amber, verified=green, suspended=orange, rejected=red, archived=slate), active-badge indicator + `valid_until` expiry, species count, completeness %, `created_at`. Default sort newest first.
- **Filters** — by `status` (multi-select), by `region`, by has-active-badge (yes/no), by export market (relation filter on `company_export_markets`), by species (relationship filter), by completeness threshold, and a global search across `legal_name` + `trade_name` + `description` (Postgres FTS, §4). Trashed filter (soft-deleted) available to admins.
- **View page** — full profile preview including all contacts (`company_contacts`), species, export markets (`company_export_markets`), gallery, documents summary, badge history, and an activity-log timeline. A "Public preview" link opens `/companies/{slug}` (works only if currently publicly visible; otherwise shows a disabled state with the reason it is hidden, derived from the visibility scope predicates).
- **Status-change actions** — discrete Filament actions, each offered only when the transition is legal (per §1.1): **Approve**, **Reject** (reason required), **Return to draft** (reason required), **Suspend** (reason required), **Reinstate** (reason required), **Archive**. Each action: requires a `reason` text input where noted, calls `CompanyStatusService`, writes the activity log, and (for approve) may prompt to issue/confirm a verification badge (cross-link to Verification domain). Reason text is mandatory and stored for suspend/reject/return; persisted on the transition's activity-log entry.
- **Feature toggle** — a per-company **Featured** boolean (`is_featured` column) with an inline toggle action, gated by the company's plan/feature entitlement (plans-as-data feature gate): only companies whose assigned subscription grants the "featured listing" feature may be toggled on; attempting to feature an ineligible company is blocked with an explanatory notice. Featured companies receive priority placement in the directory (see §4.4) and an optional "Featured" ribbon. Toggling writes to the activity log.
- Admins may edit any company field directly; admin edits do NOT auto-trigger `verified -> pending` (admins are trusted reviewers) but ARE activity-logged.

#### 3.2 SpeciesResource (full CRUD)

Full create/read/update/delete (soft delete optional per data model; if no `deleted_at` on `species`, deletes are guarded against orphaning `company_species` rows — deletion blocked while references exist, with a notice listing referencing companies).

- **List** — columns: thumbnail, common `name`, scientific name, `slug`, companies-count (how many companies handle it), `is_published` (controls whether the species SEO page is live), `updated_at`. Filter by published state; search by common + scientific name.
- **Form schema** (maps to canonical `species` columns):
  - **Core** — common `name` (required), scientific/Latin name, alternate/local names (JSONB array), `slug` (see §6).
  - **Content** — `description` (rich text), key properties (density, durability class, common uses, workability) as structured fields/JSONB, origin/region notes.
  - **Media** — primary image (`image_path`) + optional gallery (§5), stored on the **public** disk.
  - **SEO fields** — `meta_title`, `meta_description`, `og_image`, canonical override (optional; default canonical derived from `APP_URL` + route), and `is_published`. Character counters and best-practice length hints on meta fields. These feed the species detail page `<head>` and the programmatic-SEO sitemap.
- Creating/updating a species never directly affects company visibility; it only changes the multi-select options exporters can pick and the public species catalog.

### 4. Public Pages & Directory (server-rendered TALL)

All public pages are Blade + Livewire 3, server-rendered for SEO, reading exclusively through `scopePubliclyVisible()` (companies) and `Species::published()` (species). Canonical URLs and meta are built from `APP_URL` (config-driven; no hardcoded domain).

#### 4.1 Directory listing (`/companies` or `/directory`)

A Livewire component with URL-synced query params (so filtered states are shareable and crawlable). The directory is powered entirely by **PostgreSQL queries — no Meilisearch**. Behavior:

- Lists only publicly visible companies (the scope).
- **Filters**: species (multi), region (Cameroon regions), badge status (verified — effectively all listed are verified, but a "recently verified" / "badge valid" facet may be exposed), export market (multi). Filters are translated to Postgres queries:
  - species via the `company_species` pivot; export market via the `company_export_markets` relation (indexed relation join / `whereHas`).
  - region via indexed equality.
  - text search across `legal_name` + `trade_name` + `description` via Postgres full-text (`to_tsvector`/`plainto_tsquery`, GIN index) exposed through Laravel Scout's database driver / Eloquent filters. **No Meilisearch.**
- **Sorting**: featured-first (see §4.4), then by verification recency or name; sort exposed as a query param.
- **Pagination**: standard paginated, with crawlable `?page=` links and `rel=prev/next` where applicable.
- Each result card shows logo, display name (`name` accessor), region, a few species tags, export-market chips, and a "Verified profile" badge with the verification date. Wording follows LEGAL SAFETY (no "guaranteed").

#### 4.2 Company profile page (`/companies/{slug}`)

- Resolves the company by `slug` through `scopePubliclyVisible()`. Any non-visible slug returns **HTTP 404** (not 403 — we do not disclose existence of hidden/pending companies).
- **Public fields shown**: display name (`name` accessor), `description`, `region` (+ city if public), logo, cover, species handled (linking to species pages), export markets (from `company_export_markets`), gallery, and **buyer-visible contacts only** — contacts (from `company_contacts`) are filtered by the contact visibility rules; only contacts intended for buyers are exposed (private/admin-only contacts never render). A public RFQ/inquiry CTA (owned by RFQ domain) is rendered here.
- **Verification block**: shows "Verified profile", "Verification date" (badge issued date), "Valid until" (badge `valid_until`), and the standardized disclaimer: *"Documents reviewed by Cameroon Timber Hub based on information submitted by the company. Buyers should conduct final due diligence before transaction."* Never the word "guaranteed".
- **SEO**: server-rendered `<title>`/meta derived from company display name/`description`; canonical = `APP_URL` + `/companies/{slug}`; Organization/LocalBusiness structured data (JSON-LD) built from public fields; OpenGraph using logo/cover. Included in sitemap only while publicly visible.

#### 4.3 Species pages

- **Index `/species`** — lists all `is_published` species (catalog grid: thumbnail, common + scientific name, short excerpt). Searchable/filterable client-light; crawlable links to each detail page. Canonical from `APP_URL`.
- **Detail `/species/{slug}`** — resolves a published species by slug (404 if unpublished/missing). Renders description, properties, media, and a **"Verified exporters handling this species"** section that runs `Company::publiclyVisible()->whereHas('species', slug)` — so only verified, complete companies appear, and the list auto-updates as visibility changes. Uses the species `meta_title`/`meta_description`/`og_image`/canonical for `<head>`; emits `Product`/`Thing` + breadcrumb JSON-LD. These are the programmatic-SEO target pages and are prioritized in the sitemap.

#### 4.4 Featured placement

Featured companies (`is_featured = true`, entitlement-gated per §3.1) sort ahead of non-featured in directory and species-exporter lists, with an optional "Featured" ribbon. Featuring never bypasses `scopePubliclyVisible()` — a featured company that is not verified/complete still does not appear.

### 5. Image Handling & Validation

A shared `ImageService` (sitting alongside / coordinated with `DocumentService`) governs all image uploads (logo, cover, company gallery, species image/gallery, species `og_image`).

- **Storage**: images that are part of public profiles — `logo_path`, `cover_path`, company gallery, species `image_path`/gallery, and `og_image` — are stored on the **public** disk (crawlable, non-sensitive; directory-served images must be directly fetchable by crawlers/browsers). Only `company_documents` are private (private `documents` disk + signed URLs, Verification domain). The disk is abstracted so an S3 swap is config-only.
- **Validation** (form + server): MIME allowlist `jpeg`, `png`, `webp` (no SVG — XSS risk); max file size (e.g. logo ≤ 2 MB, cover/gallery ≤ 5 MB, configurable); min/max dimensions; logo enforced/cropped to square aspect via Filament image editor, cover to wide aspect (e.g. 3:1).
- **Processing**: on upload, generate responsive derivatives/thumbnails (e.g. via an `intervention/image`-backed pipeline or Filament conversions) — thumbnail for directory cards, medium for profile, original retained. Strip EXIF; re-encode to normalize/strip embedded scripts.
- **Naming/paths**: deterministic, slug- or UUID-based paths under per-entity folders (e.g. `companies/{id}/logo/...`). Stored paths recorded in `logo_path` / `cover_path` / species `image_path` / gallery relation rows.
- **Filenames** are never trusted from the client; server generates them.

### 6. Slug Generation, Uniqueness & Immutability

Applies to `companies.slug`, `species.slug`, and `pages.slug` (all unique, indexed).

- **Generation**: on create, the company slug is derived from the display name (`trade_name ?: legal_name` via the `name` accessor) and the species slug from its common name, using `Str::slug` through a shared `Sluggable` concern. Uniqueness enforced at two layers: a unique DB index (authoritative) and a generator that appends an incrementing suffix (`-2`, `-3`, …) on collision before insert.
- **Company slug immutability**: once a company has been `verified` at least once (i.e., has had a live public URL), its `slug` is **immutable** in both panels — rendered read-only. This protects inbound links, SEO equity, and shared directory URLs. Pre-verification (`draft`), the slug auto-tracks display-name changes. Admins may change a slug only via a deliberate admin-only action that simultaneously writes a 301 redirect record from the old slug (redirect handling owned by SEO domain) and logs the change.
- **Species slug**: editable by admins; any change must register a 301 redirect from the previous slug (SEO domain) and is activity-logged. Auto-tracks name only before first publish.
- Slugs are validated against a reserved-word list (e.g. `admin`, `dashboard`, `api`, `companies`, `species`) to avoid route collisions.

### 7. Empty, Placeholder & Error States

- **Directory, no results**: a friendly empty state ("No verified exporters match these filters yet") with a one-click filter reset and a link to the RFQ form ("Tell us what you need and we'll connect you"). Never an error page.
- **Species index/detail, no verified exporters yet**: the species page still renders (it is content/SEO-valuable) with a placeholder block: "We're onboarding verified exporters for this species. Submit an inquiry and we'll match you." No fake/placeholder companies.
- **Company profile missing optional media**: a neutral placeholder logo/cover (branded generic) renders when `logo_path`/`cover_path` is absent — though publicly visible companies always have a logo (enforced by the visibility scope), so the placeholder is mainly a dashboard/admin-preview concern.
- **Dashboard, no company / incomplete profile**: onboarding CTA or the completeness checklist with explicit next actions; the **Submit for review** action stays disabled with a tooltip listing unmet requirements until completeness gates pass.
- **Hidden/pending company hit on public URL**: HTTP 404 (existence not disclosed), with the standard public 404 page and a link back to the directory.
- **Suspended/expired-badge company**: drops from public surfaces automatically; the exporter dashboard shows a clear banner explaining why the profile is offline and what to do (e.g., "Your verification has expired — upload a current document to restore your listing"), cross-linking to the Documents resource.

---

## Compliance Domain

This section specifies the document, verification, and badge subsystem of Cameroon Timber Hub. It is the authoritative design for how exporter-submitted evidence is stored, reviewed, turned into public trust signals, and kept current. It depends on tables defined in the Data Model section (the single source of truth) and references them by name: `companies`, `document_types`, `company_documents`, `document_access_logs`, `document_reminder_logs`, `verification_requests`, `verification_badges`, `users`, and the `subscriptions`/feature-gating layer. All user-facing strings are localization keys (EN-first, i18n-structured); none are hardcoded.

### 1. Compliance Data Model (reference)

These tables are owned by the Data Model section; the shapes below are the compliance-relevant contract this domain relies on. Column names here MUST match the Data Model section exactly.

#### 1.1 `document_types`

Lookup table for document categories (the type is an FK row, never an inline string enum on the document). Seeded with the canonical types (see §4.1).

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| key | string | unique machine key (e.g. `business_registration`); localization label resolved by key |
| name | string | display label (localization key-friendly) |
| requires_expiry | boolean | when true, `expiry_date` is required at review-approval time |
| supports_sigif | boolean | whether `sigif_fields` capture is offered on this type |
| is_active | boolean | default true |
| created_at / updated_at | timestamps | |

#### 1.2 `company_documents`

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| company_id | bigint FK -> companies | indexed; cascade on company soft-delete (documents soft-deleted with company) |
| document_type_id | bigint FK -> document_types | indexed; the document category (NOT an inline string type) |
| status | string | enum `pending\|approved\|rejected\|needs_correction`; default `pending`; check constraint |
| visibility | string | enum `private\|admin_only\|buyer_visible\|public`; default `private`; check constraint |
| disk | string | logical disk name (default `documents`); enables later S3 swap. The private disk is named `documents` |
| storage_path | string | opaque relative path on disk; NEVER the original filename |
| original_filename | string | retained verbatim for display/download (sanitized for header only) |
| mime_type | string | server-detected MIME (not client-supplied) |
| file_size | bigint | bytes |
| checksum_sha256 | string(64) | de-dup + integrity; indexed |
| issue_date | date | nullable; from the document itself |
| expiry_date | date | nullable; drives reminder job; indexed (partial: where expiry_date is not null) |
| sigif_fields | jsonb | nullable structured SIGIF capture (see 7); GIN indexed |
| review_notes | text | nullable; internal reviewer notes |
| rejection_reason | string | nullable; localization key or free text shown to exporter on reject/needs_correction |
| reviewed_by | bigint FK -> users | nullable |
| reviewed_at | timestamp | nullable |
| uploaded_by | bigint FK -> users | the exporter user who uploaded |
| created_at / updated_at | timestamps | |
| deleted_at | timestamp | soft delete |

Indexes: `(company_id, document_type_id)`, `(company_id, status)`, partial index on `expiry_date WHERE expiry_date IS NOT NULL`, GIN on `sigif_fields`, btree on `checksum_sha256`.

#### 1.3 `document_access_logs`

Append-only audit of every private/restricted document view or download (see 3.4). Not soft-deleted.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| company_document_id | bigint FK -> company_documents | indexed |
| user_id | bigint FK -> users | nullable (null = system/job) |
| action | string | enum `view\|download\|signed_url_issued`; check constraint |
| ip_address | inet | PostgreSQL `inet` type |
| user_agent | string | truncated to 512 chars |
| context | jsonb | nullable; e.g. `{"panel":"admin","reason":"verification_review"}` |
| created_at | timestamp | indexed; no updated_at (append-only) |

#### 1.4 `verification_requests`

One row per submitted review cycle for a company.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| company_id | bigint FK -> companies | indexed |
| type | string | request type / scope |
| status | string | enum `pending\|in_review\|approved\|rejected`; default `pending`; check constraint |
| requested_badges | jsonb | nullable; array of `BadgeType` values the exporter/admin is requesting |
| assigned_to | bigint FK -> users | nullable; reviewing admin |
| decided_by | bigint FK -> users | nullable; admin who recorded the decision |
| decided_at | timestamp | nullable; the review/decision time |
| decision_notes | text | nullable |
| document_snapshot | jsonb | nullable; frozen snapshot of the documents/state at decision time |
| created_at / updated_at | timestamps | `created_at` is the submission time |

Submission time = `created_at`; review/decision time = `decided_at` (there are no separate `submitted_at`/`reviewed_at` columns).

Index: `(company_id, status)`, partial index on `status WHERE status IN ('pending','in_review')` for the queue.

#### 1.5 `verification_badges`

Multi-type model: a company may hold several active badges of different types simultaneously (at most one active badge per type).

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| company_id | bigint FK -> companies | indexed |
| badge_type | string | enum `BadgeType` (see 6.1); check constraint |
| status | string | enum `active\|revoked\|expired`; default `active`; check constraint |
| verification_request_id | bigint FK -> verification_requests | nullable; the cycle that issued it |
| verified_by | bigint FK -> users | admin who issued/verified |
| issued_at | timestamp | |
| valid_until | date | nullable; null = no expiry; indexed (partial where not null) |
| verification_notes | text | nullable |
| supporting_document_id | bigint FK -> company_documents | nullable; primary backing document |
| is_public | boolean | default true |
| reference_code | string | unique; human-friendly badge reference |
| revoked_by | bigint FK -> users | nullable |
| revoked_at | timestamp | nullable |
| revoked_reason | string | nullable |
| created_at / updated_at | timestamps | |

Constraints: **partial UNIQUE `(company_id, badge_type) WHERE status = 'active'`** — a company holds at most one active badge of each type, but may hold multiple active badges of different types. Index `(company_id, status)`, partial index on `valid_until WHERE valid_until IS NOT NULL`, unique on `reference_code`.

### 2. DocumentService Abstraction

All document I/O flows through a single `App\Domain\Compliance\Services\DocumentService`. No controller, Livewire component, Filament resource, or job touches the `Storage` facade for compliance documents directly. This is the seam that lets the private local disk be swapped for S3 by config only.

#### 2.1 Disk configuration

```php
// config/filesystems.php
'documents' => [
    'driver' => 'local',          // V2: swap to 's3' + bucket creds, no app code change
    'root' => storage_path('app/private/documents'),
    'visibility' => 'private',
    'throw' => true,
],
```

The disk name `documents` is the private disk; compliance documents are NEVER stored on the public disk. The disk name is stored per-row in `company_documents.disk` (default `documents`), so legacy rows keep resolving after a driver swap. `DocumentService` reads `disk` from the model, never a hardcoded constant.

#### 2.2 Public interface

```php
interface DocumentService
{
    public function store(Company $company, UploadedFile $file, DocumentType $type, User $uploader): CompanyDocument;
    public function temporaryUrl(CompanyDocument $doc, ?User $viewer, int $ttlSeconds = 300): string;
    public function stream(CompanyDocument $doc, ?User $viewer): StreamedResponse; // logs access
    public function delete(CompanyDocument $doc): void; // soft delete + retain blob until purge job
    public function checksum(UploadedFile $file): string;
}
```

`DocumentType` here is the `document_types` lookup row referenced by `company_documents.document_type_id`.

#### 2.3 `store()` responsibilities (upload pipeline)

1. **Validate** (server-side, never trust client):
   - MIME via `file->getMimeType()` (content sniff) against an allow-list: `application/pdf`, `image/jpeg`, `image/png`, `image/webp`. Reject `.exe`, `.svg` (XSS vector), Office macros.
   - Size: max 15 MB per file (config `compliance.max_upload_bytes`).
   - Extension cross-check against detected MIME (defense in depth).
2. **Compute** `checksum_sha256`. If an active (non-deleted) document with the same `(company_id, checksum_sha256)` exists, reject as duplicate (localization key `compliance.errors.duplicate_document`).
3. **Store blob** under an opaque path: `companies/{company_id}/{ulid}.{ext}` via the configured disk (`documents`). The ULID, not the user filename, is the on-disk name — prevents path traversal and filename collisions. The relative path is persisted to `storage_path`.
4. **Retain** `original_filename` verbatim in the column for later display/download; sanitize only when emitting the `Content-Disposition` header.
5. **Persist** row with `status = pending`, `visibility = private` (default; admin may widen later), `document_type_id`, `uploaded_by`, detected `mime_type`, `file_size`, and `disk` (default `documents`).
6. **Fire** `DocumentUploaded` event (triggers admin notification + activitylog).

#### 2.4 Signed temporary URLs

- `temporaryUrl()` returns a short-lived signed URL (default TTL 300s, config `compliance.signed_url_ttl`) routed through the app (`GET /documents/{document}/download?signature=…`), NOT a direct disk URL — this keeps access logging and authorization in our control even after an S3 swap (we sign our own route, not a presigned S3 URL, so `document_access_logs` always captures the access).
- Issuing a URL writes a `document_access_logs` row with `action = signed_url_issued`.
- The download route re-checks authorization (visibility rules, §3) at request time — a signed URL alone is insufficient if visibility narrowed after issuance.

#### 2.5 Streaming & download

`stream()` authorizes the viewer (§3.3), writes a `document_access_logs` row (`view` or `download`), then streams from disk with headers:
```
Content-Type: {mime_type}
Content-Disposition: attachment; filename="{sanitized original_filename}"
X-Content-Type-Options: nosniff
```
Inline rendering is never used for untrusted uploads (forces download / sandboxed viewer), mitigating stored-XSS.

### 3. Document Visibility Rules

`company_documents.visibility` is a four-level ladder. Visibility is set by admins during review (default `private` on upload). The rule below is enforced centrally in a `CompanyDocumentPolicy` and re-checked by the download route — never client-side only.

| visibility | Exporter (owns company) | Authenticated admin | Public buyer (no account) | Anonymous |
|---|---|---|---|---|
| `private` | View own | View | No | No |
| `admin_only` | No | View | No | No |
| `buyer_visible` | View own | View | Via gated request only* | No |
| `public` | View own | View | View | View |

\* MVP has NO buyer accounts. `buyer_visible` documents are NOT freely listed publicly; they are surfaceable only through controlled flows (e.g. an admin attaching a signed link in an RFQ response). No buyer-facing document browser exists in MVP. `public` documents (rare — e.g. a redacted certificate the company chose to publish) may render on the public company profile.

Rules:
- The actual file blob is ALWAYS behind the signed app route + policy; `public`/`buyer_visible` change *who the policy admits*, not whether logging/authorization applies.
- Every view/download of any non-`public` document writes a `document_access_logs` row. `public` document hits MAY be sampled rather than fully logged (config `compliance.log_public_access`, default false) to avoid log bloat.
- Admins can downgrade visibility at any time; widening to `public` requires an explicit admin action that is activity-logged.
- `admin_only` exists for sensitive internals (e.g. an exporter's identity document) that even the exporter should not re-download once submitted, per data-minimization.

### 4. Document Types

#### 4.1 Seeded `document_types` rows (referenced by `document_type_id`)

The `document_types` table is seeded with the canonical categories below (machine `key`). `company_documents.document_type_id` is an FK to these rows; the row also carries `requires_expiry` / `supports_sigif` metadata (the former `DocumentTypeMeta` config map is now data on the lookup table).

```
business_registration      // RCCM / company incorporation
tax_clearance              // tax / NIU attestation
sigif_registration         // SIGIF II registration evidence (fields captured, §7)
forest_concession_title    // concession / titre
export_permit              // export authorization
cites_permit               // CITES species permit
phytosanitary_certificate
legality_certificate       // legal-timber / TLAS-type evidence
quality_certificate
other
```

Each row carries metadata (the display `name`, whether `expiry_date` is expected, whether SIGIF capture applies, which `BadgeType`s it can support). Types where expiry is expected (`export_permit`, `cites_permit`, `phytosanitary_certificate`, `legality_certificate`) have `requires_expiry = true` and make `expiry_date` required at review-approval time.

### 5. Verification State Machine

Two coupled state machines: the **request** level (`verification_requests.status`) and the **per-document** level (`company_documents.status`). All transitions are performed by **Action classes** (single-purpose invokable services under `App\Domain\Compliance\Actions`) that wrap the change in a DB transaction, write activitylog, and fire a domain event. No status is mutated by directly assigning the column outside an Action.

#### 5.1 Request-level transitions

```
            submit                 claim/open
   (none) ─────────▶ pending ───────────────▶ in_review
                        │                         │
                        │                  ┌──────┴───────┐
                        │            approve│              │reject
                        ▼                   ▼              ▼
                  (exporter edits)       approved      rejected
                                            │              │
                                            └── reopen ◀────┘ (admin only, new cycle)
```

Allowed transitions (guarded; illegal transition throws `InvalidVerificationTransition`):

| From | To | Action class | Guard |
|---|---|---|---|
| (none) | pending | `SubmitVerificationRequest` | company has ≥1 `pending`/`approved` document of required types; no open request already exists; sets `created_at` as submission time |
| pending | in_review | `StartVerificationReview` | actor is admin; sets `assigned_to` |
| in_review | approved | `ApproveVerificationRequest` | all required documents resolved (each `approved` or explicitly waived); sets `decided_by`/`decided_at`; captures `document_snapshot`; issues badges (§6) |
| in_review | rejected | `RejectVerificationRequest` | `decision_notes` required; sets `decided_by`/`decided_at`; captures `document_snapshot` |
| approved/rejected | pending | `ReopenVerification` | admin only; opens a NEW request row, does not mutate the closed one |

Submission time is `created_at`; the review/decision time is `decided_at` (set by approve/reject Actions, alongside `decided_by`).

#### 5.2 Per-document transitions

```
pending ──approve──▶ approved
   │  ╲
   │   ╲─reject────▶ rejected
   │
   └──needs_correction──▶ needs_correction ──(exporter re-uploads → new doc, status pending)
```

| From | To | Action class | Effect |
|---|---|---|---|
| pending | approved | `ApproveDocument` | sets `reviewed_by/at`; if type expects expiry, `expiry_date` required; fires `DocumentApproved` |
| pending | rejected | `RejectDocument` | `rejection_reason` required; fires `DocumentRejected` |
| pending | needs_correction | `RequestDocumentCorrection` | `rejection_reason` (instructions) required; notifies exporter; fires `DocumentNeedsCorrection` |
| needs_correction | (new doc) | exporter re-upload | original retained for audit; replacement is a new `company_documents` row linked by `(company_id, document_type_id)` |

Documents are **immutable after upload**; "correction" never edits the blob — the exporter uploads a replacement, preserving the audit trail. The superseded document may be auto-set `visibility = admin_only`.

#### 5.3 Events & listeners

Domain events fired by Actions:
`DocumentUploaded`, `DocumentApproved`, `DocumentRejected`, `DocumentNeedsCorrection`, `VerificationRequestSubmitted`, `VerificationReviewStarted`, `VerificationApproved`, `VerificationRejected`, `BadgeIssued`, `BadgeRevoked`, `BadgeExpired`.

Listeners (queued where I/O-bound):
- `NotifyAdminOfSubmission` (on `VerificationRequestSubmitted`)
- `NotifyExporterOfDecision` (on approve/reject/needs_correction)
- `IssueBadgesOnApproval` is invoked inline by `ApproveVerificationRequest` (not a loose listener) so badge issuance is transactional with approval; a `BadgeIssued` event then fires for notification/logging.
- `LogComplianceActivity` (spatie/activitylog) on all of the above.

Company status coupling: `VerificationApproved` transitions `companies.status` toward `verified` (per company state machine in the Companies section); `RejectVerificationRequest` does NOT auto-suspend — it leaves the company in its prior state with a recorded rejection.

### 6. Verification Badges

The badge model is **multi-type**: a company may simultaneously hold multiple active badges of different `badge_type`s (one active per type, enforced by the partial unique index in §1.5).

#### 6.1 `BadgeType` enum (string-backed, matches `badge_type` CHECK)

```
verified_company          // "Verified Company"
verified_exporter         // "Verified Exporter"
sigif_registered          // "SIGIF Registered"
legal_timber_supplier     // "Legal Timber Supplier"
export_ready              // "Export Ready"
cites_approved            // "CITES Approved"
sustainability_profile    // "Sustainability Profile"
premium_member            // "Premium Member"
```

#### 6.2 Badge backing rules

A `BadgeType` is only issuable when its supporting `document_types` (resolved via `document_type_id`) are `approved` and unexpired. The mapping lives in config (`compliance.badge_requirements`):

| Badge | Requires approved document(s) |
|---|---|
| verified_company | business_registration |
| verified_exporter | business_registration + export_permit |
| sigif_registered | sigif_registration (with `sigif_fields` populated) |
| legal_timber_supplier | legality_certificate (+ forest_concession_title) |
| export_ready | export_permit + phytosanitary_certificate |
| cites_approved | cites_permit |
| sustainability_profile | legality_certificate (sustainability/legality evidence) |
| premium_member | active subscription (plan-gated, not document-backed) |

`ApproveVerificationRequest` issues only the requested badges (`verification_requests.requested_badges`) whose backing requirements are satisfied; unsatisfiable requested badges are reported back in `decision_notes` and not issued.

#### 6.3 Issue / revoke / expiry

- **Issue** (`IssueBadge` action): creates a `verification_badges` row, `status = active`, `verified_by` (the issuer), `issued_at = now()`, a unique `reference_code`, `supporting_document_id` (primary backing doc), `is_public = true` (default), and `valid_until` derived from the earliest `expiry_date` among backing documents (or null if none expire). The partial unique index `(company_id, badge_type) WHERE status = 'active'` guarantees one active badge per type; re-issuing a type first revokes/expires the prior active one of that same type (other types are unaffected).
- **Revoke** (`RevokeBadge` action): admin-initiated, `status = revoked`, `revoked_by/at`, `revoked_reason` required. Fires `BadgeRevoked`. Reasons include document expiry, withdrawn evidence, or compliance violation.
- **Expire**: a daily job (`ExpireBadgesJob`) flips `active` badges whose `valid_until < today` to `status = expired`, fires `BadgeExpired`. Distinct from `revoked` (intentional) — `expired` is lifecycle.
- A badge whose backing document is rejected/superseded during a later cycle is revoked with `revoked_reason = compliance.revoke.evidence_withdrawn`.

#### 6.4 Public display & legal-safe wording

Active (non-expired, non-revoked) badges with `is_public = true` render on the public company profile and directory listing. The rendered card uses ONLY approved wording (localization keys), never "guaranteed":

- Badge label (e.g. localization key `compliance.badge.verified_company`)
- Line: `compliance.badge.disclaimer` = "Documents reviewed by Cameroon Timber Hub based on information submitted by the company."
- `compliance.badge.verification_date` = "Verification date: {issued_at}"
- If `valid_until` set: `compliance.badge.valid_until` = "Valid until: {valid_until}"
- `compliance.badge.reference` = "Reference: {reference_code}"
- Footer on every profile with ≥1 badge: `compliance.badge.due_diligence` = "Buyers should conduct final due diligence before transaction."

Expired/revoked badges are NEVER shown publicly (filtered by `status = active AND is_public = true AND (valid_until IS NULL OR valid_until >= today)`). Underlying documents are not exposed by the badge; the badge is a claim, the documents stay under §3 visibility rules.

### 7. Structured SIGIF Field Capture (no integration)

MVP captures SIGIF data as structured fields ONLY — there is NO API call, lookup, or sync to SIGIF/SIGIF II. Stored in `company_documents.sigif_fields` (jsonb) on documents whose type is `sigif_registration` (and optionally on `export_permit`/`forest_concession_title` where a SIGIF reference applies — types whose `document_types.supports_sigif = true`).

Captured shape (validated on input, GIN-indexed for admin filtering):

```json
{
  "registration_number": "string",
  "permit_number": "string",
  "reference": "string",
  "issuing_authority": "string|null",
  "issued_at": "YYYY-MM-DD|null",
  "expiry": "YYYY-MM-DD|null"
}
```

Rules:
- These are operator-entered values transcribed from the uploaded document; the platform makes no representation that they were validated against SIGIF (reinforced by the §6.4 disclaimer).
- `sigif_fields.expiry`, when present, is mirrored into the document's `expiry_date` column at approval so the reminder job (§8) and badge `valid_until` derivation work uniformly.
- The `sigif_registered` badge requires `sigif_fields.registration_number` to be non-empty.
- Schema is forward-compatible: a V2 SIGIF integration can populate/verify the same JSONB keys without a migration.

### 8. Document-Expiry Reminder Job

A scheduled job keeps verification current and protects against silently stale badges.

#### 8.1 Schedule & scope

- `php artisan compliance:remind-expiring` registered in the scheduler to run **daily** (e.g. 07:00 server time).
- Scans `company_documents` where `expiry_date IS NOT NULL`, `status = approved`, `deleted_at IS NULL` (uses the partial index on `expiry_date`).
- Thresholds: **90, 60, 30 days before expiry, and on/after expiry** (`expired`). Configurable via `compliance.reminder_thresholds`.

#### 8.2 Idempotency (each threshold fires exactly once)

The `document_reminder_logs` table records which thresholds have been sent per document:

```
document_reminder_logs: id, company_document_id (FK, indexed),
  threshold (varchar CHECK in '90','60','30','expired'), sent_at, created_at
  UNIQUE (company_document_id, threshold)
```

The job computes the document's current threshold bucket and inserts a log row guarded by the unique constraint `UNIQUE(company_document_id, threshold)` (insert-or-ignore). If the row already exists, no notification is sent — guaranteeing each threshold notifies once even if the job runs multiple times per day or is retried. When a document is superseded by a re-upload, its logs are irrelevant (it is no longer `approved`/active) and the replacement starts a fresh threshold cycle.

#### 8.3 Notifications & side effects per threshold

| Threshold | Notify exporter | Notify admin | Side effect |
|---|---|---|---|
| 90 | yes (renew soon) | digest | none |
| 60 | yes | digest | none |
| 30 | yes (urgent) | yes | flag in admin queue |
| expired | yes | yes | `ExpireBadgesJob` will expire dependent badges (badge `valid_until` tracks the doc); company NOT auto-suspended — admin decides |

- Exporter notifications go to the company's onboarding/owner users (mail + dashboard notification).
- Admin notifications are aggregated into a daily compliance digest plus an in-panel queue flag for the `30`/`expired` thresholds.
- The job is queued and chunked (e.g. `chunkById(200)`) to stay within memory; per-document failures are logged and skipped, not fatal to the batch.

### 9. Admin Filament Verification Queue UX

Lives in the admin panel (`/admin`, Filament 3). Exporter-facing upload/correction lives in the `/dashboard` panel; this section covers the admin reviewer experience.

#### 9.1 Verification Queue (primary)

- A `VerificationRequestResource` list scoped by default to `status IN (pending, in_review)` (the partial index backs this), with tabs: **Pending**, **In Review (mine)**, **In Review (all)**, **Recently Decided**, **Expiring Soon** (joins documents at the `30`/`expired` threshold from §8).
- Columns: company name + current `companies.status`, submission time (`created_at`), requested_badges (badge chips), document completeness (e.g. "4/5 approved"), assigned reviewer (`assigned_to`), age in queue (color-coded SLA).
- Row actions: **Claim** (`StartVerificationReview` → sets `assigned_to`, status `in_review`), **Open review**.

#### 9.2 Review screen (per request)

- Header: company summary, links to public profile (draft view), current badges, company status.
- **Document panel**: each `company_documents` row with type (resolved via `document_type_id`), `original_filename`, status badge, issue/expiry dates (`issue_date`/`expiry_date`), a **secure viewer** button that calls `DocumentService::temporaryUrl()` (forced-download / sandboxed) — every open writes a `document_access_logs` row (`context: {"panel":"admin","reason":"verification_review"}`).
- Per-document actions (Action classes from §5.2): **Approve** (date picker for `expiry_date` when the type expects expiry; validated), **Reject** (reason required), **Needs correction** (instructions required). SIGIF fields (§7) are an editable, validated form group on `sigif_registration` documents.
- **Decision panel**: requested-badge checklist showing which are satisfiable (backing docs approved) vs blocked (with reason); **Approve request** (sets `decided_by`/`decided_at`, captures `document_snapshot`, issues satisfiable badges, transactional), **Reject request** (`decision_notes` required, sets `decided_by`/`decided_at`). Approve is disabled until required documents are resolved.
- **Badge management**: list active badges (with `reference_code`) and **Revoke** (reason required); shows expired/revoked history read-only.
- Every action is wrapped in its Action class, audit-logged via activitylog, and surfaces the legal-safe disclaimer text inline so reviewers see the wording buyers will see.

#### 9.3 Guardrails

- All write actions are gated by spatie/laravel-permission abilities (`verification.review`, `badges.issue`, `badges.revoke`, `documents.review`) and by the `CompanyDocumentPolicy`.
- Illegal state transitions are impossible from the UI (actions are only rendered when the guard in §5.1/§5.2 passes) and defended server-side (the Action throws `InvalidVerificationTransition`).
- Bulk approval is intentionally NOT offered — each document and request is decided individually to preserve review integrity.


---

---

## RFQ & Leads Domain

This section specifies the buyer-facing RFQ/inquiry intake, its trust-and-safety stack, admin triage, exporter routing, and the exporter Leads inbox. It references tables defined in the Data Model section by name (`rfqs`, `rfq_items`, `rfq_company`, `company_inquiries`, `leads`, `suspicious_events`, `companies`) and never redefines columns. Per LOCKED DECISIONS, **buyers have NO accounts/login/dashboards**, and **quotation building, pricing PDFs, and invoicing are DEFERRED** (out of MVP scope). All buyer interaction is anonymous, public, and email-verified.

### 1. Overview & Two Intake Paths

There are two distinct buyer-initiated paths, both public and login-free:

| Path | Entry point | Target | Persisted to | Routes to exporters? |
|------|-------------|--------|--------------|----------------------|
| **RFQ (Request for Quote)** | Global "Request a Quote" CTA / species pages | The platform (broadcast intent) | `rfqs` (+ `rfq_items`) | Yes, via admin triage → `rfq_company` |
| **Company Inquiry** | "Contact this exporter" on a verified company profile | One specific exporter | `company_inquiries` | Directly tied to one `company_id`; surfaced in that exporter's inbox |

Both paths converge into the exporter `/dashboard` Leads inbox and into the `leads` table for lightweight pipeline tracking. Neither path is actionable by staff or exporters until the buyer's email has been verified (see §3).

Legal posture: all confirmation and profile copy uses the approved wording. RFQ acknowledgements state that submission does not constitute a contract and that "Buyers should conduct final due diligence before transaction."

### 2. Public Request-a-Quote Form (no login)

Server-rendered TALL Livewire component at the public route `GET /request-quote` (and a species-prefilled variant `GET /species/{species:slug}/request-quote`). Submission handler is a Livewire action; on success it writes one `rfqs` row with `status = new` and `email_verified_at = null`, writes one or more `rfq_items` line-item rows, then dispatches the verification email (§3).

#### 2.1 Fields & validation

Buyer-contact and commercial fields are stored on `rfqs`; the requested product line items are stored as `rfq_items` rows (one per species/product line). There is **no** `specs` JSONB blob — the structured form fields map directly onto typed columns. Validation runs as a Livewire `rules()` set; messages come from translation files (no hardcoded strings).

**`rfqs` (header) fields:**

| Field | Column | Rules |
|-------|--------|-------|
| Buyer name | `buyer_name` | required, string, 2–120 |
| Buyer email | `buyer_email` | required, email (RFC + DNS `email:rfc,dns`), max 180, lowercased |
| Buyer company | `buyer_company` | nullable, string, max 160 |
| Country | `buyer_country_code` | required, ISO-3166-1 alpha-2, exists in country list |
| Phone (E.164) | `buyer_phone` | nullable, string, regex `^\+?[1-9]\d{6,14}$` |
| Destination country | `destination_country_code` | required, ISO-3166-1 alpha-2 |
| Shipping port | `shipping_port` | nullable, string, max 160 |
| Incoterm | `incoterm` | nullable, enum: `EXW`,`FOB`,`CFR`,`CIF`,`DAP` |
| Target price | `target_amount` | nullable, decimal(14,2), >= 0 |
| Currency | `target_currency` | required_with target_amount, enum: `XAF`,`USD`,`EUR`,`GBP`,`CNY` |
| Deadline | `deadline` | nullable, date |
| Message / details | `notes` | required, string, 20–4000 |
| Consent to contact | (not persisted; gate) | accepted (boolean true) |
| Honeypot | `website` | must be empty (see §4) |
| Turnstile token | `cf-turnstile-response` | conditionally required (see §4) |

**`rfq_items` (line items) — at least 1, max 10 rows:**

| Field | Column | Rules |
|-------|--------|-------|
| Species (catalog) | `species_id` | nullable, exists in `species` (set when chosen from catalog) |
| Species (free text) | `species_text` | nullable, string, max 160; required if no `species_id` |
| Product form | `form` | required, enum: `logs`, `sawn`, `veneer`, `plywood`, `other` |
| Grade | `grade` | nullable, string, max 60 |
| Dimensions | `dimensions` | nullable, string, max 160 |
| Quantity | `quantity` | required, decimal, > 0, <= 1,000,000 |
| Unit | `unit` | required, enum: `m3`, `ton`, `pcs`, `container` |
| Moisture content | `moisture_content` | nullable, string, max 60 |

The form's repeater maps each requested product line (species + product/dimensions + quantity) to one `rfq_items` row. Server-side normalization before insert: trim all strings; lowercase `buyer_email`; uppercase currency/incoterm/country codes; coerce each item `quantity` to decimal. `reference_code` is generated server-side (§8). `ip_address` (inet) is captured for anti-spam, never displayed publicly. `spam_score` (int) and `is_spam` (bool) are computed at insert (§4.4).

#### 2.2 Post-submit UX

On valid submission the component shows a neutral confirmation ("Check your email to confirm this request") regardless of spam score — risk handling is silent and server-side to avoid tipping off abusers. The raw RFQ is NOT visible to admins as actionable until verified, but unverified rows are retained for abuse analytics and auto-pruned (§7).

### 3. Email-Verification Step

No RFQ or inquiry is triaged, routed, or shown to exporters until the buyer proves control of the email address. The email-verification gate sets `email_verified_at` before any admin acts.

- On submit, generate a **signed, expiring URL** via Laravel's signed routes:
  `GET /rfq/{rfq}/verify` named `rfq.verify`, built with `URL::temporarySignedRoute('rfq.verify', now()->addHours(48), ['rfq' => $rfq->id, 'h' => sha1($rfq->buyer_email)])`. The `h` parameter binds the link to the stored email; the signature middleware (`signed`) rejects tampering/expiry.
- The verification email is a queued Mailable (`RfqVerificationMail`) sent on the `mail` queue. Subject and body come from translation files.
- On a valid hit: set `rfqs.email_verified_at = now()`, transition nothing else (status stays `new`), fire `RfqVerified` event, and show a success page. The event triggers admin notification (review queue badge) and re-runs spam scoring with the "email confirmed" signal.
- Expired/invalid signature → friendly page offering to **resend** (rate-limited, see §4.2). Resend regenerates a fresh signed link; it does not create a new `rfqs` row.
- The same pattern applies to `company_inquiries` via `GET /inquiry/{inquiry}/verify` named `inquiry.verify`, setting `company_inquiries.email_verified_at`.

`email_verified_at` (nullable timestamp) is the single gate. A scoped query `Rfq::verified()` (`whereNotNull('email_verified_at')`) backs every admin/exporter-facing list.

### 4. Anti-Spam Stack

Layered defenses; each layer is independently toggleable via config (`config/trust.php`) so staff can tighten/loosen without code changes.

#### 4.1 Honeypot

A visually hidden `website` input (and a synced timestamp field `form_rendered_at`). Bots that fill `website`, or submit faster than a configurable minimum (`trust.min_form_seconds`, default 3s), are silently rejected: the request returns the same neutral confirmation, no row is written, and a `suspicious_events` row is recorded with `event_type = honeypot_triggered`.

#### 4.2 Throttling / rate limiting

Named rate limiters (registered in a service provider) applied via middleware on the public routes:

- `rfq-submit`: max 5 submissions / hour / IP and 3 / hour / email (composite key `ip|email`).
- `inquiry-submit`: max 8 / hour / IP.
- `rfq-verify-resend`: max 3 / 15 min / rfq.
- A global `public-forms` limiter: 30 / min / IP guarding all intake endpoints.

Breaches return HTTP 429 with a translated message and write a `suspicious_events` row (`event_type = rate_limited`). Limiter state lives in Redis.

#### 4.3 Cloudflare Turnstile (config-optional)

Turnstile is integrated but **off by default**, gated by `trust.turnstile.enabled` + `site_key`/`secret_key` (env). When enabled, the widget renders in both forms and the `cf-turnstile-response` token is server-verified (`https://challenges.cloudflare.com/turnstile/v0/siteverify`) inside a custom validation rule. Failure → rejection + a `suspicious_events` row (`event_type = suspicious_rfq`, with the failure detail in `context`). When disabled, the rule is skipped entirely, so the form works in local/dev without keys.

#### 4.4 Duplicate / burst detection & spam heuristics

At insert time a `RfqRiskService` computes `spam_score` (int 0–100) and sets the boolean `is_spam` flag on the `rfqs` row. The individual heuristic flags that drive the score are captured in the related `suspicious_events.context` JSONB (not on the RFQ row). Heuristics:

| Flag | Condition |
|------|-----------|
| `free_email_high_volume` | `buyer_email` domain in free-provider list AND a line-item `quantity` above a per-unit threshold (config) |
| `burst_ip` | ≥ N submissions from same `ip_address` within window (e.g. 3 / 10 min) |
| `duplicate_recent` | Near-identical (same email + species set + quantity) RFQ within 24h |
| `disposable_email` | Domain matches disposable-email blocklist |
| `mismatched_geo` | `buyer_country_code` vs IP geo country mismatch (best-effort, never hard-blocks) |
| `link_in_message` | URLs detected in `notes` body |
| `oversized_quantity` | A line-item `quantity` implausibly high for the chosen `unit` |

Scoring is additive with config-weighted points. Bands: `< 30` normal, `30–69` flagged (still enters queue, visually badged), `>= 70` sets `is_spam = true` and surfaces in a **Spam** sub-queue (row persisted; `status` is set to `spam` only by an admin action or, if `trust.autospam_threshold` is met, automatically). Burst/duplicate hits also append a `suspicious_events` row — `event_type = rapid_rfq_burst` for IP bursts and `event_type = duplicate_submission` for near-duplicates — with `subject_type`/`subject_id` pointing at the offending RFQ and `ip_address`, `buyer_email`, and matched-flag details carried in `context`. Heuristics never block silently at high scores beyond queue placement — admins always retain final say, except confirmed honeypot/rate-limit which are dropped pre-insert.

`suspicious_events` is an append-only audit feed. Per LOCKED DECISIONS its columns are: `id`, `event_type` (varchar + CHECK in `honeypot_triggered, rate_limited, rapid_rfq_burst, repeated_failed_login, suspicious_rfq, duplicate_submission`), `severity` (`low,medium,high`), `subject_type`/`subject_id` (nullable morph to the RFQ/inquiry), `user_id` (FK, null), `ip_address` (inet, null), `context` (JSONB — holds `buyer_email`, payload, matched flags, etc.), `created_at`. It uses `event_type` and `context` (NOT `type`/`payload`/a top-level `buyer_email`). It powers an admin "Trust & Safety" log and feeds future tuning.

### 5. Company Inquiry Flow (contact an exporter)

A lighter path scoped to one exporter, available only on **verified** company profiles (`companies.status = verified`).

- Route: Livewire component embedded on the public profile; submit handler writes one `company_inquiries` row with `company_id`, `email_verified_at = null`, and `status = new`.
- Per LOCKED DECISION J, `company_inquiries` columns are: `id, company_id FK, name, email, phone (null), message (text), status, email_verified_at (null), ip_address (null), timestamps`.
- Fields: `name` (required, 2–120), `email` (required, email rfc/dns), `phone` (nullable, E.164), `message` (required, 20–3000), consent gate, honeypot, optional Turnstile. Same normalization rules as §2.1 (trim, lowercase email).
- Same anti-spam stack applies (`inquiry-submit` limiter, honeypot, optional Turnstile, lightweight spam heuristics reusing `RfqRiskService` in "inquiry" mode; any signals recorded as `suspicious_events` rows with the appropriate `event_type` and `context`).
- Email verification via `inquiry.verify` (§3). Only after verification does the inquiry appear in the target exporter's Leads inbox and generate a `leads` row.
- Inquiries are **not** admin-triaged/broadcast — they are 1:1 with the named exporter. Admins can still view them in a read-only Filament resource and mark spam if abused.

### 6. Admin Triage (Filament `/admin`)

A Filament Resource `RfqResource` plus dedicated queue pages provide the staff workflow over **verified** RFQs.

#### 6.1 Review queue

- Default table = `Rfq::verified()` ordered by `created_at desc`, with tabs/filters by `status` (`new`, `in_review`, `approved`, `rejected`, `spam`, `closed`) and a "Flagged" tab (`spam_score >= 30` OR `is_spam = true`).
- Columns: `reference_code`, `buyer_name`/`buyer_country_code`, species/line-item summary (from `rfq_items`), aggregate quantity+unit, `spam_score` (color-coded badge), `status`, `created_at`. `is_spam` shown as a chip; the row's `suspicious_events` (with their `event_type`/`context`) accessible via a relation/modal.
- Row + bulk actions:
  - **Start review** → `status: new → in_review`.
  - **Mark spam** → `status: → spam` (also sets `is_spam = true` and logs an activity entry via activitylog).
  - **Reject** → `status: → rejected` with required internal reason.
  - **Approve** → `status: → approved`; enables routing (§6.2).
  - **Close** → `status: → closed` (terminal, e.g. after handoff).
- All transitions are guarded by a small state machine (allowed sets per current status) and recorded in the activity log with the acting admin. Permissions via spatie roles (`rfqs.triage`, `rfqs.route`).

#### 6.2 Routing an approved RFQ to exporters

Only an `approved` RFQ can be routed. A Filament action **Route to exporters** opens a modal:

- Exporter picker lists only `companies.status = verified` (optionally filtered by matching species from the RFQ's `rfq_items` and by active subscription feature gate `leads.receive`).
- Admin selects one or more exporters. For each selection the action creates an `rfq_company` row: `{ rfq_id, company_id, status: 'sent', routed_at: now(), routed_by }`, idempotently — the **UNIQUE (rfq_id, company_id)** constraint prevents duplicates, so re-routing to an already-routed exporter is a no-op. `rfq_company.status` follows the lifecycle `sent, viewed, responded, declined` (default `sent`).
- Creating each `rfq_company` row also upserts a `leads` row for that exporter (`source = 'rfq'`, `status = new`, link back via `rfq_company`), and dispatches a queued `RfqRoutedToExporterMail` notification to the exporter.
- Admins can later **add** more exporters (additional `rfq_company` rows) without changing the RFQ status. A routed RFQ may be moved to `closed` once handled; routing history remains.

### 7. Retention & Lifecycle

- Unverified `rfqs`/`company_inquiries` (`email_verified_at IS NULL`) older than 7 days are soft-deleted by a scheduled job (`PruneUnverifiedIntakeJob`); `suspicious_events` are retained longer (config `trust.events_retention_days`, default 180) for tuning.
- `rfqs` use soft deletes (`deleted_at`) per conventions; hard purge of soft-deleted rows after a config window via a scheduled cleanup.
- Status timestamps of interest (`routed_at` on `rfq_company`, verification timestamps) are immutable once set except by explicit admin correction (audited).

### 8. Human-Friendly `rfqs.reference_code`

Each RFQ gets a unique, readable `reference_code` generated server-side at creation and shown in all buyer/admin/exporter surfaces:

- Format: `RFQ-YYYY-XXXXX` — e.g. `RFQ-2026-0A3F7` — where `YYYY` is the creation year and `XXXXX` is a base32 (Crockford, no ambiguous chars) encoding of a per-year sequence, zero-padded to 5.
- Implementation: a per-year counter (Postgres sequence or a `SELECT … FOR UPDATE` on a small `rfq_counters` table) inside the creation transaction guarantees no collisions under concurrency; `reference_code` has a UNIQUE index. The generator is encapsulated in `RfqReferenceGenerator` so the scheme can evolve.
- The `reference_code` is the token buyers quote in any follow-up email and the primary human key staff search by.

### 9. Exporter Leads Inbox (`/dashboard` Filament panel)

The company-side Filament panel (`/dashboard`) — accessed under the single `web` guard, with access scoped to the authenticated exporter's `company_id` via `company_user` membership — exposes a **Leads** experience combining routed RFQs and direct inquiries. Visibility is feature-gated by the company's plan (`leads.receive`, plus a plan cap on monthly visible leads if configured).

#### 9.1 What an exporter sees

- **Routed RFQs**: rows from `rfq_company` where `company_id` = the exporter's company. The exporter sees a **buyer-safe projection** of the parent `rfqs` plus its `rfq_items` — species, product form, quantity/unit, dimensions, grade, destination, incoterm, target price, message (`notes`), `reference_code` — and the buyer's contact details (name, email, phone, country). Internal admin notes, `spam_score`, `is_spam`, `ip_address`, and `suspicious_events` are **never** exposed to exporters.
- **Direct inquiries**: verified `company_inquiries` for their `company_id`.
- A unified Leads list is backed by the `leads` table (`source` ∈ `rfq`/`inquiry`), with drill-through to the underlying RFQ projection or inquiry.

#### 9.2 Status actions

- On a routed RFQ, the exporter updates `rfq_company.status` through the allowed lifecycle: `sent → viewed` (auto-set on first open), then exporter-driven `viewed → responded` or `viewed → declined`. `responded`/`declined` are terminal for that pairing.
- On the pipeline `leads` row, the exporter sets `lead.status` through the enum `new, contacted, won, lost, dormant`: `new → contacted → won` / `lost`, or `→ dormant`; transitions guarded by an allowed-set state machine. `won`/`lost` are terminal but reopenable to `contacted` by the exporter if needed (audited).
- **Private notes**: exporters add free-text notes (a `lead_notes`-style relation or `leads.notes` JSONB append) visible only within their own `/dashboard`. Notes are never shown to buyers or other exporters and are excluded from any public/buyer projection.
- Marking `rfq_company.status = responded` may auto-advance the linked `leads.status` to `contacted` (configurable).

#### 9.3 Boundaries

- Exporters cannot see which **other** exporters an RFQ was routed to, nor the buyer-platform-internal triage state (`rfqs.status`). They only see their own `rfq_company` pairing and `leads` row.
- No messaging/threading, quotation builder, or pricing PDF is provided — **quotations and buyer accounts are explicitly DEFERRED**. The MVP scope is: receive lead → record outreach status → keep private notes. Any reply happens off-platform (the exporter contacts the buyer directly using the provided email/phone).

### 10. Events, Jobs & Notifications (summary)

- Events: `RfqSubmitted`, `RfqVerified`, `RfqRoutedToExporter`, `InquirySubmitted`, `InquiryVerified`, `SuspiciousEventRecorded`.
- Queued Mailables: `RfqVerificationMail`, `InquiryVerificationMail`, `RfqRoutedToExporterMail`, and an admin `NewVerifiedRfqMail`/notification for the review queue.
- Jobs (on Redis queue): `ComputeRfqRiskJob` (or synchronous at insert with async re-score on verify), `PruneUnverifiedIntakeJob`, `PurgeSoftDeletedIntakeJob`.
- All user-facing strings (emails, validation, confirmations) live in `lang/en/*` per the i18n-structured requirement; French is V2.

---

## SEO Architecture

This section defines the public-facing URL structure, per-page SEO contracts (title/meta/H1/canonical/structured data), sitemap and robots configuration, internal-linking strategy, the editable `pages` content model, and the i18n-ready URL approach. It is the single source of truth for how the public TALL site is rendered for search engines and crawlers. All canonical and absolute URLs are derived from `config('app.url')` (`APP_URL`); no domain is ever hardcoded.

### 1. Public Route Map

All public routes are server-rendered (Blade + Livewire 3), defined in `routes/web.php`, and resolved by SEO-friendly slugs (never numeric IDs). Slugs are unique and indexed on `companies`, `species`, and `pages` (per the data model). Trailing slashes are normalized off; uppercase paths 301-redirect to lowercase.

| Method | URL Pattern | Route Name | Page Type | Purpose |
|---|---|---|---|---|
| GET | `/` | `home` | Home | Brand landing; value proposition; primary RFQ CTA; site search entry; featured verified exporters and species. |
| GET | `/timber-exporters-cameroon` | `seo.exporters` | Directory Landing | Primary keyword landing page for the exporter directory; filterable list of verified companies. |
| GET | `/cameroon-timber-suppliers` | `seo.suppliers` | Directory Landing (alias) | Secondary keyword landing; same directory dataset, distinct copy/intent; canonicalized (see §3.2). |
| GET | `/species` | `species.index` | Species Index | Catalog of all published timber species; entry to species detail and programmatic pages. |
| GET | `/species/{slug}` | `species.show` | Species Detail | Per-species reference page: description, technical attributes, SIGIF fields, verified suppliers handling it. |
| GET | `/companies/{slug}` | `companies.show` | Company Profile | Public verified company profile with badge, documents marked `buyer_visible`/`public`, species handled, inquiry CTA. |
| GET | `/request-quote` | `rfq.create` | RFQ Intake | Public multi-step RFQ form (email verify + anti-spam); global quote request. |
| GET | `/list-your-company` | `onboarding.landing` | Conversion Landing | Exporter acquisition page; explains onboarding, plans, verification; CTA into `/dashboard` registration. |
| GET | `/pricing` | `pricing` | Marketing/Plans | Renders plans-as-data (tiers, features) with manual-assignment messaging; no checkout. |
| GET | `/verification` | `verification.explainer` | Marketing/Trust | Explains the verification process and legally-safe trust wording (see §8). |
| GET | `/about` | `about` | Marketing | Company/mission content (editable `pages` record). |
| GET | `/contact` | `contact` | Marketing/Intake | General contact + inquiry form (email verify + anti-spam). |
| GET | `/exporters/{species}-cameroon` | `pseo.exporters` | Programmatic SEO | Generated long-tail page combining a species with the exporter intent (e.g. `/exporters/sapelli-cameroon`). |

Notes on routing:

- `{slug}` (companies, species) uses route-model binding on the `slug` column. Unpublished/`draft`/`archived` companies and unpublished species 404 (not soft 200), so crawlers never index thin/empty records.
- The programmatic segment is `/exporters/{species}-cameroon`. The `{species}` param is a **plain string** (NOT route-model binding): a route constraint `->where('species', '[a-z0-9-]+-cameroon')` captures the species slug followed by the literal `-cameroon` suffix, and the controller then **strips the `-cameroon` suffix** from the string to look up the matching `species.slug` itself. (Route-model binding is deliberately avoided here because the URL token is not a bare slug.) A programmatic page only renders (HTTP 200, indexable) when at least one `verified` company is linked to that species; otherwise it 404s to avoid doorway/thin pages.
- `/request-quote` and `/contact` GET-render the form; submission is handled by Livewire components (no separate POST route needed in the map). RFQ submission persists to `rfqs` per the data model.

### 2. Page-Type SEO Contracts

Every page type has exactly **one** `<h1>`. Titles, meta descriptions, canonical, and JSON-LD are produced by a central `SeoService` and rendered via a shared `<x-seo.head>` Blade component. All user-facing strings (including templated SEO copy fragments) come from translation files (`lang/en/seo.php`), never hardcoded — see §7. The literal brand suffix is `Cameroon Timber Hub`.

Placeholders below (`:name`, `:species`, `:region`, `:count`) are interpolated server-side. Meta descriptions are clamped to ~155 chars; titles to ~60 chars (the `SeoService` truncates on word boundaries with no ellipsis injected into the canonical title token).

#### 2.1 Home (`/`)
- **Title:** `Cameroon Timber Hub — Verified Timber Exporters & Suppliers`
- **Meta:** `Connect with verified Cameroonian timber exporters. Browse species, review compliance documents, and request quotes from suppliers — all in one B2B hub.`
- **H1:** `Verified Cameroonian Timber Exporters & Suppliers`
- **Canonical:** `{APP_URL}/`
- **JSON-LD:** `WebSite` (with `potentialAction` → `SearchAction`), plus `Organization` for the platform itself.

#### 2.2 Directory Landing (`/timber-exporters-cameroon`, `/cameroon-timber-suppliers`)
- **Title:** `Timber Exporters in Cameroon — Verified Directory | Cameroon Timber Hub`
- **Meta:** `Browse :count verified timber exporters in Cameroon. Filter by species, region, and verification status. Review compliance documents and request quotes.`
- **H1:** `Verified Timber Exporters in Cameroon`
- **Canonical:** `{APP_URL}/timber-exporters-cameroon` (the alias `/cameroon-timber-suppliers` sets its canonical to itself but is cross-linked; see §3.2 for the duplicate-content rule).
- **JSON-LD:** `BreadcrumbList` + `CollectionPage` describing the directory; individual cards are not marked up as separate `Organization` nodes here (that lives on the profile page).

#### 2.3 Species Index (`/species`)
- **Title:** `Cameroon Timber Species Catalog | Cameroon Timber Hub`
- **Meta:** `Explore Cameroonian timber species — properties, common uses, and verified exporters supplying each. Sapelli, Ayous, Iroko, Tali and more.`
- **H1:** `Cameroon Timber Species Catalog`
- **Canonical:** `{APP_URL}/species`
- **JSON-LD:** `BreadcrumbList` + `CollectionPage`.

#### 2.4 Species Detail (`/species/{slug}`)
- **Title:** `:name Timber from Cameroon — Properties & Verified Suppliers | Cameroon Timber Hub`
- **Meta:** `:name (:botanical_name) from Cameroon: technical properties, common uses, and verified exporters supplying this species. Request quotes from reviewed suppliers.`
- **H1:** `:name Timber from Cameroon`
- **Canonical:** `{APP_URL}/species/:slug`
- **JSON-LD:** `Product` by default (the species as a tradeable product: `name`, `description`, `category`, `additionalProperty` for technical/SIGIF attributes); `Article` is available as an optional template choice for content-heavy species. Always accompanied by `BreadcrumbList`. If a FAQ block is present, add `FAQPage` (see §6).

#### 2.5 Company Profile (`/companies/{slug}`)
- **Title:** `:name — Verified Timber Exporter in Cameroon | Cameroon Timber Hub`
- **Meta:** `:name is a verified timber exporter profile on Cameroon Timber Hub. Documents reviewed based on information submitted by the company. Verified :verified_date; valid until :valid_until.`
- **H1:** `:name`
- **Canonical:** `{APP_URL}/companies/:slug`
- **Display name:** `:name` resolves through the `Company::name` Eloquent accessor (`trade_name ?: legal_name`); there is **no** `name` column on `companies`. Title, H1, meta, `Organization.name`, and breadcrumb all read the same accessor.
- **JSON-LD:** `Organization` (`name` ← accessor, `url`, `logo` ← `logo_path` on the public disk, `address` with `addressCountry: CM`, `description`). Do **not** emit any `rating`/`aggregateRating` (no reviews in MVP) and never emit claims implying a guarantee. Accompanied by `BreadcrumbList`. The visible trust line uses the legally-safe wording in §8.

#### 2.6 RFQ Intake (`/request-quote`)
- **Title:** `Request a Timber Quote from Cameroon Exporters | Cameroon Timber Hub`
- **Meta:** `Request quotes from verified Cameroonian timber exporters. Tell us your species, volume, and destination — we route your request to matching suppliers.`
- **H1:** `Request a Timber Quote`
- **Canonical:** `{APP_URL}/request-quote`
- **JSON-LD:** `BreadcrumbList`. `noindex` is **not** applied (the form is a valuable intent page), but the form itself carries anti-spam (see RFQ section). Thank-you/confirmation states are `noindex` (see §3.3).

#### 2.7 Conversion Landing (`/list-your-company`)
- **Title:** `List Your Timber Company — Reach Verified Buyers | Cameroon Timber Hub`
- **Meta:** `Are you a Cameroonian timber exporter? Create a verified profile, upload compliance documents, and receive buyer inquiries. Start your listing today.`
- **H1:** `List Your Timber Company on Cameroon Timber Hub`
- **Canonical:** `{APP_URL}/list-your-company`
- **JSON-LD:** `BreadcrumbList` + `FAQPage` (onboarding FAQ block).

#### 2.8 Pricing (`/pricing`)
- **Title:** `Pricing & Plans for Timber Exporters | Cameroon Timber Hub`
- **Meta:** `Compare Cameroon Timber Hub plans for timber exporters. Choose the listing and verification features that fit your business. Plans assigned by our team.`
- **H1:** `Plans for Timber Exporters`
- **Canonical:** `{APP_URL}/pricing`
- **JSON-LD:** `BreadcrumbList` + `FAQPage` (pricing FAQ). No `Offer`/`Product` price markup with `priceCurrency` is emitted as a purchasable offer, since there is no checkout in MVP and plans are manually assigned (avoids misrepresenting a transactable price).

#### 2.9 Verification Explainer (`/verification`)
- **Title:** `How Verification Works | Cameroon Timber Hub`
- **Meta:** `Learn how Cameroon Timber Hub reviews exporter documents and issues verified profiles. Understand verification dates, validity, and buyer due diligence.`
- **H1:** `How Verification Works`
- **Canonical:** `{APP_URL}/verification`
- **JSON-LD:** `BreadcrumbList` + `FAQPage`. All copy uses §8 wording.

#### 2.10 About (`/about`)
- **Title:** `About Cameroon Timber Hub | Verified B2B Timber Trade`
- **Meta:** `Cameroon Timber Hub connects verified Cameroonian timber exporters with international buyers through transparent, document-based profiles.`
- **H1:** `About Cameroon Timber Hub`
- **Canonical:** `{APP_URL}/about`
- **JSON-LD:** `BreadcrumbList` (+ `Organization` reference).

#### 2.11 Contact (`/contact`)
- **Title:** `Contact Cameroon Timber Hub | Cameroon Timber Hub`
- **Meta:** `Get in touch with Cameroon Timber Hub. Send buyer inquiries or platform questions — verified before routing to keep the marketplace trustworthy.`
- **H1:** `Contact Cameroon Timber Hub`
- **Canonical:** `{APP_URL}/contact`
- **JSON-LD:** `BreadcrumbList` + `ContactPage`.

#### 2.12 Programmatic Exporters (`/exporters/{species}-cameroon`)
- **Title:** `:species Exporters in Cameroon — Verified Suppliers | Cameroon Timber Hub`
- **Meta:** `Find verified :species exporters in Cameroon. :count reviewed suppliers handle :species — compare profiles, check documents, and request a quote.`
- **H1:** `:species Exporters in Cameroon`
- **Canonical:** `{APP_URL}/exporters/:species-cameroon`
- **JSON-LD:** `BreadcrumbList` + `CollectionPage`. If a templated FAQ block is rendered, add `FAQPage`. These pages are indexable only when ≥1 `verified` company is linked (otherwise 404, per §1) to prevent thin/doorway pages.

#### 2.13 Shared head contract
Every page additionally emits, via `<x-seo.head>`:
- `<link rel="canonical">` (absolute, `APP_URL`-driven).
- Open Graph (`og:title`, `og:description`, `og:url`, `og:type`, `og:image`, `og:site_name=Cameroon Timber Hub`, `og:locale=en`) and Twitter Card (`summary_large_image`) tags, derived from the same title/meta tokens.
- `<html lang="{{ app()->getLocale() }}">` for locale signaling.
- `hreflang` self-reference for `en` now, with a structured slot for `fr` in V2 (see §7).
- Robots meta defaulting to `index,follow`, overridable per page to `noindex,follow` (see §3.3).

### 3. Indexation & Canonical Rules

#### 3.1 Canonical generation
A single helper `seo_canonical()` (wrapping `URL::to()` against `config('app.url')`) builds every canonical and absolute OG URL — **every** base URL is derived from `APP_URL`; no domain is hardcoded anywhere in code, config, sitemaps, or `robots.txt`. Query strings used purely for filtering/pagination on directory and index pages (`?species=`, `?region=`, `?page=`) are **stripped** from the canonical (canonical points to the clean path), except `?page=N` which canonicalizes to itself for pages ≥2 to keep deep pagination indexable without duplicating page 1.

#### 3.2 Duplicate directory landings
`/timber-exporters-cameroon` and `/cameroon-timber-suppliers` serve the same underlying verified-company dataset with deliberately distinct intro copy and `<title>`/H1 phrasing. Each is self-canonical (both indexable as distinct keyword landings), but they explicitly cross-link to each other in body copy and share the `pages`-driven intro blocks. If post-launch analytics show cannibalization, the alias can be switched to `rel=canonical → /timber-exporters-cameroon` by setting the alias `pages.canonical_url` column (the nullable per-page override; see §5) without a code change.

#### 3.3 noindex surfaces
The following are emitted with `noindex,follow`: RFQ/contact confirmation ("thank you") states, any email-verification interstitial pages, filtered directory permutations beyond a sane facet whitelist (e.g. unknown query params), and any company whose `status` is not `verified` if ever reachable. `/admin` and `/dashboard` (Filament panels) are blocked in `robots.txt` and additionally send `noindex`.

### 4. Sitemap & robots.txt

#### 4.1 Dynamic sitemap (`spatie/laravel-sitemap`)
A scheduled job (`GenerateSitemapJob`, run daily via the scheduler and on-demand after relevant model events) builds a **sitemap index** at `/sitemap.xml` referencing child sitemaps, written to the public disk:

- `/sitemap-static.xml` — home, directory landings, `/species`, `/pricing`, `/verification`, `/about`, `/contact`, `/list-your-company`, `/request-quote`.
- `/sitemap-companies.xml` — one entry per `verified` company (`/companies/{slug}`); `lastmod` from `updated_at`.
- `/sitemap-species.xml` — one entry per published species (`/species/{slug}`); `lastmod` from `updated_at`.
- `/sitemap-pseo.xml` — one entry per programmatic `/exporters/{species}-cameroon` page that currently has ≥1 verified linked company.

Rules:
- Only indexable URLs are included (excludes `noindex` surfaces, non-`verified` companies, unpublished species, and pSEO pages with zero verified suppliers).
- `changefreq`/`priority`: home `weekly`/`1.0`; directory & species index `daily`/`0.9`; species detail & pSEO `weekly`/`0.7`; company profiles `weekly`/`0.6`; marketing pages `monthly`/`0.4`.
- Every absolute URL in the sitemap is generated from `APP_URL` via `seo_canonical()`; the route `/sitemap.xml` serves the generated index from storage (signed/public read), so no domain is baked into the XML beyond `APP_URL`.

#### 4.2 robots.txt
Served from `routes/web.php` (dynamic, so `APP_URL` drives the `Sitemap:` line) — not a static file:

```
User-agent: *
Allow: /
Disallow: /admin
Disallow: /dashboard
Disallow: /login
Disallow: /register
Disallow: /*?*sort=
Disallow: /*?*utm_

Sitemap: {APP_URL}/sitemap.xml
```

In non-production environments (`app()->environment() !== 'production'`) robots.txt returns `Disallow: /` for all agents, to keep staging out of the index.

### 5. Editable `pages` Content Model

Marketing/landing copy is **not** hardcoded in Blade. A `pages` table backs editable content surfaces, managed in the Filament admin panel. Per the data model, `pages` columns are: `id`, `slug` (unique), `title`, `h1`, `meta_description`, `data` (jsonb — the body content), `schema_json` (jsonb, null), `canonical_url` (varchar, null — per-page canonical override), `template`, `is_published`, timestamps. The page-type Blade templates read structured content blocks from the matching `pages` record by a stable `slug` key (`home`, `directory-exporters`, `directory-suppliers`, `list-your-company`, `pricing`, `verification`, `about`, `contact`).

Body content is stored in the **`data` jsonb column** (NOT a `content` column) holding ordered blocks (hero, prose sections, FAQ items, CTA labels). Per-page SEO overrides come from first-class columns: `title`, `h1`, `meta_description`, `schema_json` (custom JSON-LD), and `canonical_url` (the nullable canonical override). Resolution order for each SEO field: `pages` column value (when set) → `SeoService` template default. This lets admins refine copy and FAQ entries (which feed `FAQPage` JSON-LD, read from `data`) without deploys, while species/company/pSEO pages derive SEO from their own records + `SeoService` templates rather than `pages`.

Editable blocks containing user-facing labels still resolve through the translation layer for non-content chrome (buttons, nav), keeping the i18n contract intact (§7).

### 6. Internal Linking Strategy

The link graph is designed so every indexable entity is reachable within a few clicks and authority flows toward verified profiles and high-intent landings:

- **Home →** directory landing, `/species`, top featured verified companies, top species, `/request-quote`, `/list-your-company`.
- **Directory landing →** individual `/companies/{slug}` cards; faceted links to `/exporters/{species}-cameroon` programmatic pages for each species represented; link to `/species`.
- **Species index →** each `/species/{slug}`; secondary links to the matching `/exporters/{species}-cameroon` page.
- **Species detail (`/species/{slug}`) →** lists the **verified** companies that handle that species (each linking to `/companies/{slug}`, label via the `Company::name` accessor), and links to its sibling programmatic page `/exporters/{species}-cameroon`. Links to `/request-quote` prefilled with the species context.
- **Company profile (`/companies/{slug}`) →** links to each species the company handles (`/species/{slug}`) and back to the directory landing; inquiry CTA to `/contact`/inquiry and `/request-quote`.
- **Programmatic page (`/exporters/{species}-cameroon`) →** links up to the canonical species detail (`/species/{slug}`), across to the directory landing, lists verified companies for that species (`/companies/{slug}`), and cross-links to a small set of related species' programmatic pages (e.g. species in the same family/use-case) to build a tight pSEO mesh without orphan pages.
- **All pages →** persistent footer/nav links to `/`, directory landing, `/species`, `/pricing`, `/verification`, `/about`, `/contact`, `/list-your-company`.

Linking integrity rules: company↔species links are driven by the `company_species` pivot and only render when the company is `verified` and the species is published; broken-link risk is avoided because route-model binding 404s unpublished targets (and the pSEO controller's suffix-stripped lookup 404s when no verified company is linked), and the link builders filter on the same conditions used by the sitemap.

### 7. i18n-Ready URL & Content Approach (EN now; FR in V2)

- **No hardcoded user-facing strings:** all titles, meta, H1s, CTAs, FAQ chrome, and trust wording resolve through Laravel localization (`lang/en/*.php`, keys like `seo.species.title`, `trust.verified_line`). The `SeoService` accepts a locale and pulls templated tokens from translation files; the brand token `Cameroon Timber Hub` is itself a translatable constant.
- **EN-first URLs without a prefix now:** MVP serves English at the bare path (no `/en` segment) to keep current URLs clean and avoid a launch-time redirect churn.
- **V2 FR strategy (documented, not built):** French will be served under a locale path prefix `/fr/...` via a `LocaleMiddleware` and a route-group wrapper (`Route::prefix('{locale}')->where('locale','fr')`). The default (unprefixed) tree remains English. Slugs become translatable: a future `species_translations` / localized-slug approach (or per-locale slug columns) maps EN↔FR slugs; canonical and `hreflang` tags already reserve a slot so V2 only fills in the `fr` alternate. Each page will then emit reciprocal `hreflang="en"` and `hreflang="fr"` plus `x-default` pointing at the EN tree. The `<x-seo.head>` component is built now to accept an `alternates` array (empty except self in MVP) so adding FR is additive, not structural.
- **Locale signaling today:** `<html lang>` and `og:locale=en` / `hreflang="en"` self-reference are emitted in MVP so the EN baseline is explicit before FR exists.

### 8. RFQ CTA, FAQ Placement & Legally-Safe Trust Wording

#### 8.1 RFQ CTA placement
A prominent "Request a Quote" CTA (a shared `<x-cta.request-quote>` component routing to `/request-quote`) appears: in the home hero, sticky in the directory landing, on every species detail (prefilled with that species), on every company profile (prefilled with that company so the RFQ can route via `rfq_company`), and on each programmatic page (prefilled with that species). The component label is translatable. Company-profile inquiries seed the lead/RFQ routing pipeline so the exporter lead inbox receives context.

#### 8.2 FAQ block placement (feeds `FAQPage` JSON-LD)
FAQ blocks (sourced from the `pages` `data` jsonb for marketing pages, or templated per-record for species/pSEO) appear on: `/verification`, `/pricing`, `/list-your-company`, species detail, and programmatic exporter pages. Wherever a FAQ block renders, the page emits matching `FAQPage` structured data built from the same Q/A pairs (no mismatch between visible and marked-up content, per structured-data guidelines).

#### 8.3 Legally-safe trust wording (mandatory, used verbatim across all surfaces)
Verification copy must **never** state or imply that the platform guarantees a company. Approved phrasings:
- Status label: **"Verified profile"**.
- Provenance line: **"Documents reviewed by Cameroon Timber Hub based on information submitted by the company."**
- Date labels: **"Verification date: :date"** and **"Valid until: :date"** (driven by the `verification_badges` `issued_at` / `valid_until` columns).
- Due-diligence disclaimer (shown on company profiles, the verification explainer, and near RFQ CTAs): **"Buyers should conduct final due diligence before transaction."**

These strings live in `lang/en/trust.php` and are reused by the company profile (`/companies/{slug}`), the verification explainer (`/verification`), directory cards, and the RFQ flow. The company display name in all of these surfaces comes from the `Company::name` accessor (`trade_name ?: legal_name`). A revoked or expired badge (a `verification_badges` row whose `status` is `revoked`/`expired`) removes the "Verified profile" label and provenance line, and such companies are excluded from `verified`-only listings and the sitemap.

---

## Security, Privacy & Audit

This section enumerates the security, privacy, and audit controls **implemented in the MVP**, the events captured by the audit log, the data visibility model, and controls explicitly **deferred** to later phases. All controls assume the locked stack: Laravel 12.x / PHP 8.3, PostgreSQL, Redis, a **single `web` auth guard** over the `users` table fronting two **role-gated** Filament 3 panels (`/admin` for platform staff, `/dashboard` for company users), a custom TALL public site, and a private local filesystem disk (`documents`) fronted by `DocumentService`.

---

### 1. Authentication & Account Security

| Control | Requirement |
|---|---|
| Password hashing | Bcrypt (Laravel default, `config/hashing.php`), automatic rehash on login when work factor changes. Never store or log plaintext passwords. |
| Email verification | All user accounts MUST implement `MustVerifyEmail`. Unverified users may sign in but are gated (via middleware) out of all document/profile-management actions until `email_verified_at` is set. Public buyer RFQ/inquiry submitters verify via a signed, expiring email link (setting `email_verified_at` on the `rfqs` / `company_inquiries` row) before the submission is admitted (see RFQ section). |
| 2FA (both panels) | TOTP-based two-factor authentication is REQUIRED for both Filament panels (`/admin` and `/dashboard`). Implemented via Filament's built-in MFA / `filament/filament` TOTP feature (or `laravel/fortify` TOTP under the hood), enforced by a `RequiresTwoFactor` middleware on each panel. Platform-staff accounts (`/admin`) MUST enroll before reaching the panel (forced enrollment redirect). Company accounts (`/dashboard`) are prompted to enroll on first login; enrollment becomes mandatory before document submission. Recovery codes (single-use, hashed at rest) are issued at enrollment. |
| Password policy | Minimum 12 characters, enforced via `Password::min(12)->mixedCase()->numbers()->uncompromised()` (k-anonymity HIBP check) on registration and reset. |
| Password reset | Standard Laravel signed, expiring (60 min) reset tokens; single-use; invalidates other sessions on reset. |
| Session security | Redis-backed sessions; `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`; session fixation prevented by regenerating the session ID on login. A **single `web` guard and session cookie** serve the whole application; isolation between the public site, the `/admin` panel, and the `/dashboard` panel is enforced by **role + `canAccessPanel()`** authorization (not by separate guards or separate cookies). |
| Login throttling | `throttle` on login routes: max 5 failed attempts per (email + IP) per minute, with exponential lockout; applied to both Filament panel logins and any public auth route. Backed by Redis rate limiter. |

---

### 2. Authorization (RBAC + Policies)

- **Library:** `spatie/laravel-permission`, all roles/permissions registered under the **default (`web`) guard** — no per-guard duplication. Roles and permissions are seeded data; role assignment is a manual admin action (audited).
- **Single guard, two panels:** ONE `web` guard authenticates every user. Panel access is decided by `canAccessPanel(User)`:
  - `/admin` requires a **platform-staff role** (`super_admin`, `admin`, `verification_officer`, `content_manager`).
  - `/dashboard` requires **company membership** (a `company_user` row). Cross-panel access is denied.
- **Platform-staff roles (spatie, default guard):** `super_admin`, `admin`, `verification_officer`, `content_manager`. (Seedable-but-unused-in-MVP: `sales_officer`, `finance_officer`, `support_officer`.) spatie roles govern `/admin` only.
- **Platform permissions (exact slugs):** `companies.view`, `companies.manage`, `companies.suspend`, `documents.review`, `verification.review`, `badges.issue`, `badges.revoke`, `species.manage`, `rfqs.triage`, `rfqs.route`, `pages.manage`, `plans.manage`, `users.manage`, `audit.view`.
  - `super_admin`: ALL.
  - `admin`: all of the above EXCEPT `companies.suspend`, `plans.manage`, `users.manage`.
  - `verification_officer`: `companies.view`, `documents.review`, `verification.review`, `badges.issue`, `badges.revoke`, `audit.view`.
  - `content_manager`: `companies.view`, `species.manage`, `pages.manage`.
- **Company-side authorization (`/dashboard`):** governed by the `company_user.role` enum (`owner` = full company access; `manager` = limited; `member` = read), **NOT** by spatie roles. For MVP a user belongs to exactly ONE company (single-company dashboard, no Filament tenancy).
- **Enforcement rule:** EVERY state-changing action (controller method, Livewire action, Filament action, queued job entrypoint that acts on a model) MUST authorize through a Laravel **Policy** or an explicit `can`/permission gate. No action relies on UI hiding alone.
- **Policies required (one per protected model):** `CompanyPolicy`, `CompanyDocumentPolicy`, `VerificationRequestPolicy`, `VerificationBadgePolicy`, `RfqPolicy`, `LeadPolicy`, `SubscriptionPolicy`, `UserPolicy`.
- **Company scoping:** `/dashboard` queries are scoped to the user's company (via the `company_user` membership) using a global scope + policy `before`-style ownership checks; a company user can never read or mutate another company's `companies`, `company_documents`, `rfq_company`, or `leads` rows. `super_admin` bypasses scope via Gate `before`.

---

### 3. Input, Transport & App-Layer Hardening

| Control | Requirement |
|---|---|
| CSRF | Laravel `VerifyCsrfToken` on all web/stateful routes (public TALL forms, both Filament panels). Livewire requests carry the CSRF token automatically. |
| HTTPS / HSTS | TLS enforced at the edge; app sets `Strict-Transport-Security` (1 year, `includeSubDomains`). `URL::forceScheme('https')` in production. |
| Security headers | Global middleware sets: `Content-Security-Policy` (self + explicitly allowlisted asset/Turnstile origins, no inline-script except nonce'd), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` (clickjacking), `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` (deny camera/mic/geolocation), `X-XSS-Protection: 0`. |
| Output escaping | Blade `{{ }}` auto-escaping everywhere; `{!! !!}` permitted ONLY on server-controlled, sanitized CMS/page content. User/company free-text is never rendered raw. |
| Mass-assignment | Models use explicit `$fillable`; status/verification/ownership columns are guarded and only set via service methods, never from request input. |
| SQL injection | Eloquent / query builder bindings only; no raw interpolation. Full-text search uses parameterized `to_tsquery`/`plainto_tsquery`. |
| General rate limiting | Named limiters: `public-forms` (RFQ/inquiry submit), `auth` (login/reset), `signed-url` (document fetch), `global` API-style cap per IP. Configured in `RouteServiceProvider`, Redis-backed. |
| Signed URLs (non-file) | Email-verification and password-reset links use Laravel signed/temporary-signed routes with expiry. |

---

### 4. File Upload & Document Security

All document handling is mediated by `DocumentService` (storage abstraction so an S3 swap is config-only). Documents live on the **private `documents` disk** only (`company_documents.disk` defaults to `'documents'`); they are **never** stored on the public disk or web-served from `public/`. (Company/species images — `logo_path`, `cover_path`, species `image_path`, gallery — live on the **public** disk and are out of scope here.)

- **Upload validation (enforced server-side on every upload):**
  - **MIME / extension allowlist:** `pdf, jpg, jpeg, png, webp` only; validated with Laravel `File::types()` + `mimetypes:` rule (real MIME sniffing, not just client extension).
  - **Size cap:** max 10 MB per file (configurable), enforced in validation and in PHP/Nginx limits.
  - **Filename hygiene:** original name stored as metadata; the stored object's `storage_path` uses a random ULID/UUID path; no user-controlled path segments (prevents traversal).
  - **Content-type pinning:** files served with their stored MIME and `Content-Disposition: attachment` to prevent inline script execution; never served as `text/html`.
- **Private storage + signed URLs:** Downloads are issued only via short-lived **temporary signed URLs** (e.g., 5-minute expiry) generated by `DocumentService::temporaryUrl()`, gated by policy before the URL is minted. Direct path access is impossible from the web root.
- **Access logging (`document_access_logs`):** every view/download (URL mint AND fetch) writes a row capturing `company_document_id`, `user_id` (nullable for admin-system), `action` (`view`, `download`, `signed_url_issued`), `ip_address`, `user_agent`, `created_at`. Used for anti-fraud and access review. (This table is append-only; not soft-deleted.)
- **Visibility enforcement:** `company_documents.visibility` (`private`, `admin_only`, `buyer_visible`, `public`; default `private`) is enforced in `CompanyDocumentPolicy` AND at URL-minting time — visibility is never trusted from the client.

---

### 5. Anti-Spam & Anti-Fraud

**Anti-spam (public RFQ + inquiry forms):**
- **Honeypot:** hidden field that must remain empty (bots fill it); submissions with a filled honeypot are silently rejected.
- **Time-trap:** a signed timestamp on form render; submissions faster than a minimum threshold (e.g., < 2s) are rejected.
- **CAPTCHA:** Cloudflare **Turnstile** server-side token verification on every public submission; failures rejected. Site/secret keys via env, not hardcoded.
- **Throttle:** `public-forms` rate limiter (per IP + per email) caps submissions; bursts are blocked.
- **Email verification gate:** an RFQ/inquiry is not routed to companies until the submitter confirms via a signed email link (sets `email_verified_at`), defeating fire-and-forget spam.

**Anti-fraud (`suspicious_events`):** an append-only table recording flagged signals for admin review. Columns: `id`, `event_type` (varchar + CHECK in: `honeypot_triggered`, `rate_limited`, `rapid_rfq_burst`, `repeated_failed_login`, `suspicious_rfq`, `duplicate_submission`), `severity` (`low`, `medium`, `high`), `subject_type`/`subject_id` (nullable morph), `user_id` (FK, nullable), `ip_address` (nullable), `context` (JSONB — holds `buyer_email`, payload, and other request detail), `created_at`. Events are raised by listeners/middleware and surfaced in an admin Filament resource; no automated banning in MVP (review-only).

---

### 6. Audit Logging (`spatie/laravel-activitylog`)

Audit is implemented with `spatie/laravel-activitylog` using its **standard schema** (no custom columns added to `activity_log`). Auditable models use the `LogsActivity` trait with `logOnlyDirty()` and an explicit attribute allowlist (no secrets/passwords logged). Authentication and routing/system events that aren't simple model writes are logged via the `activity()` helper from listeners.

**Fields captured per entry** (standard `activity_log` columns):
- `log_name` (channel: `auth`, `company`, `document`, `verification`, `rfq`, `subscription`, `admin`)
- `description` (human-readable, localizable key)
- `causer` (the acting `User`, morph; nullable for system/guest)
- `subject` (the affected model, morph)
- `properties` JSONB — includes `old` → `attributes` (new) diffs for updates
- **`ip_address` and `user_agent`** — written **into the `properties` JSONB** by a global activity tap/middleware (`LogActivityContext`) that calls `activity()->withProperties([...])` / `CauserResolver`-style context for the request lifecycle. **No extra `ip_address`/`user_agent` columns are added to `activity_log`** — provenance rides inside `properties`.
- `created_at`

**Events that MUST be logged:**

| Channel | Logged events |
|---|---|
| `auth` | login success, login failure (causer = attempted email/IP), logout, password reset, 2FA enrolled/disabled, email verified |
| `company` | company create, company update (dirty diff), **company suspend** / unsuspend (`companies.suspend`), status changes (`draft→pending→verified→suspended→rejected→archived`), company member added/removed, soft delete/restore |
| `document` | document upload, document **approve** / **reject** (`documents.review`), `needs_correction` set, visibility change, document soft delete |
| `verification` | verification request created, moved to `in_review`, approved/rejected (`verification.review`), **badge issue** (`badges.issue`), **badge revoke** (`badges.revoke`), badge expiry (system causer) |
| `rfq` | **rfq create** (after email verify), status changes (`new→in_review→approved/rejected/spam/closed`), **rfq route** (`rfqs.route`; per-company `rfq_company` send/viewed/responded/declined), lead created/status change |
| `subscription` | **plan assignment** (manual, `plans.manage`), plan change, subscription status change (`active→expired→cancelled`) |
| `admin` | any role/permission grant or revoke (`users.manage`), any privileged config change, manual data correction, impersonation (if used) |

Audit entries are **immutable** (no update/delete via app code) and retained indefinitely in MVP. They are admin-readable only — gated by `audit.view` — surfaced as a read-only Filament resource and on each record's detail page.

---

### 7. Data Visibility Matrix

Defines who can see each data category. **Public** = anonymous internet (and indexable for SEO where noted); **Buyer-visible** = exposed to verified buyers via public profile/RFQ flow (no buyer accounts in MVP, so this is "shown on public profile to inquiring buyers"); **Admin-only** = platform staff via `/admin`; **Company-only** = the owning company user via `/dashboard` (plus admin).

| Data category | Public | Buyer-visible | Admin-only | Company-only (owner) |
|---|:---:|:---:|:---:|:---:|
| Company name (`trade_name`/`legal_name`), slug, country/region, summary | ✅ (indexable) | ✅ | ✅ | ✅ |
| Company **verified** status + badge (date, `valid_until`) | ✅ (verified profiles) | ✅ | ✅ | ✅ |
| Public-facing contact / RFQ button | ✅ | ✅ | ✅ | ✅ |
| Species catalog + species SEO pages | ✅ (indexable) | ✅ | ✅ | ✅ |
| Company–species offerings (`company_species`) | ✅ | ✅ | ✅ | ✅ |
| Company internal notes, raw contact email/phone (`company_contacts`) | ❌ | ❌ | ✅ | ✅ (own) |
| Uploaded documents (`visibility=private`/`admin_only`) | ❌ | ❌ | ✅ | ✅ (own, if private) / ❌ (admin_only) |
| Uploaded documents (`visibility=buyer_visible`) | ❌ | ✅ (signed URL) | ✅ | ✅ (own) |
| Uploaded documents (`visibility=public`) | ✅ (signed URL) | ✅ | ✅ | ✅ (own) |
| Verification request internals, reviewer notes (`decision_notes`) | ❌ | ❌ | ✅ | ❌ |
| SIGIF structured fields (captured, not integrated) | ❌ | ❌ | ✅ | ✅ (own, read) |
| RFQ content + submitter PII | ❌ | ❌ | ✅ | partial: only the slice routed to that company via `rfq_company` |
| Leads / lead inbox | ❌ | ❌ | ✅ | ✅ (own company's leads only) |
| Subscription / plan assignment | ❌ | ❌ | ✅ | ✅ (own, read) |
| `document_access_logs`, `suspicious_events`, `activity_log` | ❌ | ❌ | ✅ (`audit.view`) | ❌ |
| Pages/CMS marketing content | ✅ (indexable) | ✅ | ✅ | ✅ |

**Legal-safety wording (enforced on all public verification surfaces):** badges/profiles display "Verified profile", "Verification date", "Valid until", and "Documents reviewed by Cameroon Timber Hub based on information submitted by the company. Buyers should conduct final due diligence before transaction." No copy may "guarantee" a company.

---

### 8. Privacy & Data Handling

- **Soft deletes** (`deleted_at`) on `companies`, `company_documents`, `rfqs`, `users` allow recovery and preserve audit references; hard deletion is an admin-only, audited operation. Audit, access-log, and suspicious-event tables are append-only and excluded from soft delete.
- **PII minimization:** buyer submitters provide only what the RFQ requires (name, email, optional phone, message); no buyer profiles persist beyond the RFQ/lead records. Submitter email is verified but not used for marketing.
- **Data subject requests:** handled manually by admins in MVP (locate by email, export or redact via admin tooling); no automated portal.
- **Secret handling:** all credentials (DB, Redis, mail, Turnstile keys, `APP_KEY`) live in `.env` / environment, never in VCS; `.env` is git-ignored; `APP_KEY` rotation procedure documented. Signed-URL and session integrity depend on `APP_KEY` secrecy.

---

### 9. Deferred Controls (explicitly NOT in MVP)

The following are recognized and intentionally deferred; the MVP is architected so they slot in without rework:

- **Real antivirus scanning (ClamAV):** MVP validates MIME/extension/size only. A real AV scan is deferred and designed as a **queued post-upload hook** — documents would enter a `pending_scan` state and be released only after a clean result. The upload pipeline already routes through `DocumentService`, so the hook attaches cleanly later.
- **Field-level / application-layer encryption** beyond Laravel defaults (encrypted cookies, hashed passwords/recovery codes, TLS in transit). At-rest column encryption for sensitive fields (e.g., SIGIF identifiers, contact PII) is deferred to V2.
- **WAF / CDN / edge DDoS protection** — treated as **infrastructure/ops**, not app code; deferred to deployment hardening (e.g., Cloudflare in front). App-layer rate limiting is the MVP substitute.
- **Automated fraud response** (auto-ban, IP blocklists, velocity rules acting without a human) — MVP is review-only via `suspicious_events`.
- **SSO / SAML / enterprise identity, automated DSAR portal, and buyer accounts** — out of MVP scope.

---

### 10. Operations Notes (security-adjacent)

- **Backups:** nightly automated PostgreSQL backups (logical dump + retention policy) and periodic backup of the private `documents` disk; restores tested. Treated as an ops responsibility, documented in the runbook (not app code), but called out here because document loss/disclosure is a security concern.
- **Secret rotation:** documented procedure for rotating `APP_KEY`, DB, Redis, mail, and Turnstile credentials.
- **Patch hygiene:** Composer/NPM dependency updates and Laravel security releases tracked; `composer audit` run in CI.
- **Log retention:** application/audit logs retained indefinitely in MVP; access logs reviewed during incident handling. Centralized log shipping is deferred to ops.

---

## Testing Strategy

This section defines the **Pest**-based testing approach for the Cameroon Timber Hub MVP. It specifies the test pyramid, the domain logic earmarked for strict TDD, the full test inventory grouped by domain area (each line = one test intent), and the factory/seeder fixtures every test relies on. Table and column names referenced here are authoritative against the Data Model section; never introduce conflicting names.

### Testing Stack & Conventions

- **Runner:** Pest 3 on PHP 8.3, layered over PHPUnit. Spec uses Pest function style (`it(...)`, `test(...)`, `expect(...)`).
- **Database:** A dedicated PostgreSQL test database (not SQLite) so JSONB, full-text search, GIN/partial indexes, and check constraints behave identically to production. The badge **partial UNIQUE (company_id, badge_type) WHERE status = 'active'** is exercised directly, so SQLite is unsuitable. `RefreshDatabase` per test; transactional where possible.
- **Suites (`phpunit.xml`):** `Unit` (pure domain logic, no DB), `Feature` (HTTP, Livewire, Filament, jobs, DB), and a tagged `Domain` group for the TDD-first logic below. CI runs `php artisan test --parallel`.
- **HTTP/Blade:** Laravel HTTP tests for public TALL pages (assert status, `<title>`, meta, JSON-LD).
- **Livewire:** `Livewire::test()` for the public RFQ/inquiry/registration components.
- **Filament:** Filament's `livewire()`/page test helpers for the `/admin` and `/dashboard` panels, asserting `canAccessPanel()` outcomes, resource access, and policy gating. Both panels run over the single `web` guard; `/admin` access is keyed off a platform staff role (`super_admin`/`admin`/`verification_officer`/`content_manager`) and `/dashboard` off `company_user` membership.
- **Jobs/Scheduler:** `Queue::fake()`, `Bus::fake()`, `Mail::fake()`, `Notification::fake()`, and `Storage::fake('documents')` (the private disk); `$this->travelTo()` for time-based expiry/reminder logic.
- **Auth:** `actingAs($user)` over the single `web` guard for both company-dashboard users and platform staff (no second guard). Platform permission assertions via spatie gates (default guard); company-side authorization asserted via `company_user.role` (owner/manager/member).
- **Coverage target:** 100% line coverage on the three TDD domain services below; meaningful feature coverage on every MVP-IN flow. No test asserts verification "guarantees" — wording assertions follow the legal-safety constraints.

### Domain Logic Best Suited to TDD (write tests first)

These three units are pure, rule-dense, and high-risk. They are developed test-first in the `Unit`/`Domain` suite with no framework dependencies (plain PHP services taking value objects / enums), then wired into Eloquent/Filament/jobs.

1. **Verification State Machine** — governs `company.status` (`draft → pending → verified → suspended → rejected → archived`), `verification_request.status` (`pending → in_review → approved/rejected`), and `verification_badge.status` (`active → revoked/expired`). Encodes the legal allowed/forbidden transition matrix, side effects (approving a request issues a badge of the requested `badge_type` and flips the company to `verified`), and guards (cannot verify a company with no `approved` `company_documents`). Honors the one-active-badge-per-type rule when issuing.
2. **RFQ Intake & Anti-Spam Decision** — pure function over a submitted RFQ payload + request signals (honeypot field, submission timestamp/elapsed-fill-time, per-IP/per-email rate counters, email-verification state) returning a decision enum (`accept` → `rfq.status = new`, `quarantine` → `spam`, `reject`). Deterministic and exhaustively testable in isolation.
3. **Expiry Threshold Calculation** — given a `verification_badge.valid_until` (or `company_document.expiry_date`) and "today", computes which reminder thresholds (`90` / `60` / `30` days before expiry, plus `expired`) are due, ensuring idempotency keys so a threshold fires at most once. Pure date math, ideal for table-driven Pest datasets.

---

### Factories

All factories live in `database/factories`. States cover every enum value needed by the inventory.

| Factory | Key attributes / states |
|---|---|
| `UserFactory` | base user; states: `superAdmin()`, `admin()`, `verificationOfficer()`, `contentManager()` (each assigns the matching spatie platform role on the default guard); company-membership states `owner()`, `manager()`, `member()` (attach to a company via the `company_user` pivot with the matching `role`); `unverifiedEmail()`. |
| `CompanyFactory` | `legal_name`, `trade_name`, unique `slug`, `status` (default `draft`); states: `draft()`, `pending()`, `verified()`, `suspended()`, `rejected()`, `archived()`; `withOwner()` (attaches a user via `company_user` with `role = owner`), `withSpecies()`, captures SIGIF fields (structured columns only, no integration). Exposes the `name` accessor (`trade_name ?: legal_name`) for display assertions. |
| `SpeciesFactory` | `common_name`, `scientific_name`, unique `slug`; state `withSeoPage()`. Used for catalog + species SEO page tests. |
| `DocumentFactory` (`company_documents`) | `document_type_id` (FK → `document_types`), `status` (default `pending`), `visibility` (default `private`), `storage_path`, `disk` (default `documents`), `issue_date`, `expiry_date`; states: `approved()`, `rejected()`, `needsCorrection()`, and per-`visibility` states `private()/adminOnly()/buyerVisible()/public()`; `expiringInDays(int)` (sets `expiry_date`); uses `Storage::fake('documents')` paths on the private disk. |
| `RfqFactory` | `buyer_name`/`buyer_email`/`buyer_country_code`, `reference_code`, `status` (default `new`), JSONB `attachments`, optional `species_id` on items; states: `new()`, `inReview()`, `approved()`, `rejected()`, `spam()`, `closed()`; `unverifiedEmail()`, `withHoneypot()`. |
| `PlanFactory` | `name`, `slug`, `features` (JSONB feature flags), `price` decimal(14,2) + `currency`; states: `free()`, `pro()`. Plus a `SubscriptionFactory` linking company↔plan with `status` (`active`/`expired`/`cancelled`) and period dates for feature-gating tests. |

Supporting factories referenced by inventory: `VerificationRequestFactory` (states `pending/inReview/approved/rejected`), `VerificationBadgeFactory` (sets `badge_type` from the canonical CHECK list — e.g. `verified_company`, `verified_exporter`, `sigif_registered`, `legal_timber_supplier`, `export_ready`, `cites_approved`, `sustainability_profile`, `premium_member`; states `active/revoked/expired`, `validUntil()`, plus a `withType(string $badgeType)` state for multi-type coverage; respects the partial unique active constraint), `LeadFactory` (statuses `new/contacted/won/lost/dormant`), `CompanyInquiryFactory`, and a `RfqCompanyFactory` (pivot, statuses `sent/viewed/responded/declined`).

### Seeders

Deterministic seeders used by both demo environments and the test bootstrap (a `DatabaseSeeder` composes them; feature tests call the specific ones they need).

- **`RolesPermissionsSeeder`** — spatie roles on the **default guard**: `super_admin`, `admin`, `verification_officer`, `content_manager` (plus seedable-but-unused-in-MVP `sales_officer`, `finance_officer`, `support_officer`) and the exact permission slugs (`companies.view`, `companies.manage`, `companies.suspend`, `documents.review`, `verification.review`, `badges.issue`, `badges.revoke`, `species.manage`, `rfqs.triage`, `rfqs.route`, `pages.manage`, `plans.manage`, `users.manage`, `audit.view`), wired to the canonical role→permission matrix. Company-side roles (`owner`/`manager`/`member`) are NOT spatie roles — they live on the `company_user` pivot. Source of truth for RBAC tests.
- **`PlanSeeder`** — the plans-as-data set (e.g. Free, Pro) with feature-flag JSONB; underpins feature-gating tests and manual assignment.
- **`SpeciesReferenceSeeder`** — a stable reference set of timber species (common + scientific names, slugs) for catalog and species-SEO-page tests.
- **`DemoVerifiedCompanySeeder`** — one fully `verified` company: an `owner` plus a `member` user (via `company_user`), `approved` documents across visibilities on the private `documents` disk, one `active` `verification_badge` per several `badge_type`s (each with a future `valid_until`, respecting the one-active-per-type constraint), attached species, and an assigned active subscription. Anchors directory, profile, badge-display, and scoping tests.

---

### Test Inventory (grouped by area; each line = one test intent)

#### Exporter Registration & Onboarding
- Public registration creates a `draft` company + owner user linked via `company_user` with `role = owner`, fires email verification.
- Registration validation rejects duplicate company slug/email and missing required fields.
- Onboarding wizard advances a company from `draft` to `pending` only when required profile fields + at least one uploaded document exist.
- A newly registered owner can access the `/dashboard` Filament panel (company membership grants `canAccessPanel()`); a user with no company membership cannot.
- Onboarding writes structured SIGIF fields without invoking any external integration.

#### Company Verification State Transitions
- Valid: `draft → pending → verified` succeeds via approving a verification request and issues an `active` badge of the requested `badge_type` as a side effect.
- Valid: `verified → suspended` and `suspended → verified` (reinstate) transitions persist and are audit-logged.
- Valid: `pending → rejected` sets `verification_request.status = rejected` and leaves company unverified.
- Invalid: `draft → verified` (skipping review) is forbidden and throws/rejects without state change.
- Invalid: verifying a company with zero `approved` `company_documents` is blocked by the state-machine guard.
- Invalid: transition out of `archived` is forbidden.
- State machine is exhaustively table-tested over the allowed/forbidden matrix (Pest dataset).

#### Document Upload, Visibility & Signed-URL Access
- Upload accepts allowed MIME types (pdf/jpeg/png) within the size cap and persists a `pending` `company_document` on the private `documents` disk with `visibility = private` by default.
- Upload rejects disallowed MIME type with a validation error and stores nothing.
- Upload rejects files over the size cap with a validation error.
- `visibility = private` documents are never exposed to public profile or buyer-facing endpoints.
- `visibility = admin_only` documents are visible to platform staff only, not to the owning company user.
- `visibility = buyer_visible` / `public` documents surface only through their intended channels per the visibility matrix.
- `DocumentService` generates a time-limited signed URL; a valid signature streams the file from the private `documents` disk (using `storage_path`).
- A tampered or expired signature returns 403 and streams nothing.
- A signed-URL hit writes an access-log entry (actor, document, timestamp) via activitylog.
- A user from Company B cannot obtain a signed URL for Company A's document (company-data scoping enforced).

#### Verification Badge Issue / Revoke / Expiry
- Approving a verification request issues an `active` badge of the requested `badge_type` with `valid_until` set per policy.
- A company may hold multiple `active` badges of different `badge_type`s simultaneously; issuing a second `active` badge of the **same** type is blocked by the partial unique index (one active per type).
- Admin revoke flips badge to `revoked`, removes the public "Verified profile" indicator, and is audit-logged.
- A badge past `valid_until` is computed/marked `expired` and no longer renders as active on the public profile.
- Public badge display uses legal-safe wording ("Verified profile", "Verification date", "Valid until", due-diligence disclaimer) and never the word "guarantee".
- Re-issuing a given `badge_type` after expiry creates a fresh `active` badge of that type without mutating the historical revoked/expired record (partial unique constraint still satisfied because only the new row is `active`).

#### RFQ Public Submission, Email Verification & Anti-Spam
- Valid public RFQ submission with later email verification creates an `rfq` with `status = new` (sets `email_verified_at`).
- Unverified-email RFQ is held (not routed/triaged) until the email-verification link is confirmed.
- Honeypot field populated → submission is quarantined as `spam`, no routing occurs.
- Rapid repeat submissions from same IP/email exceed throttle → blocked/`spam` per anti-spam decision.
- Sub-threshold fill-time (bot-speed submit) is flagged by the anti-spam decision unit.
- Anti-spam decision unit is table-tested across honeypot/timing/rate/verification permutations (Pest dataset).
- Submitted RFQ persists buyer fields (`buyer_country_code` etc.) + `attachments` JSONB and optional item `species_id`.

#### Admin RFQ Triage & Routing
- Admin moves an RFQ `new → in_review → approved`; approving routes it to selected companies.
- Routing to N companies creates N `rfq_company` rows with `status = sent`.
- Marking an RFQ `spam` or `rejected` performs no routing and creates no `rfq_company` rows.
- A company viewing a routed RFQ flips its `rfq_company.status` to `viewed`; responding flips to `responded`, declining to `declined`.
- Admin triage actions are audit-logged with the acting staff user.

#### Company Inquiries Flow
- Public company-profile inquiry submission (email-verified + anti-spam) creates a `company_inquiries` record tied to the target company.
- Inquiry appears in the owning company's lead inbox and can create/associate a `lead` with `status = new`.
- Lead status advances through `new → contacted → won/lost/dormant` and persists.
- A company user sees only inquiries/leads scoped to their own company.

#### RBAC, Guards & Company Data Scoping
- A staff role (`super_admin`/`admin`/`verification_officer`/`content_manager`) can access `/admin` resources via `canAccessPanel()`; a user with only company membership is denied `/admin`.
- A company `owner` can manage own company profile/documents; `manager` has a limited set and `member` is read-only — enforced by `company_user.role`, not spatie roles.
- A `/dashboard` (company-membership) user cannot reach `/admin`, and a permission-gated staff action (e.g. `documents.review`, `badges.issue`) is denied to company users (per-permission gate tests over the single `web` guard).
- Company A user querying/viewing Company B's company, documents, RFQs, or leads is denied (global scope / policy enforced) — company-data scoping.
- Plan feature-gating: a company on the Free plan is blocked from Pro-gated features; manual admin assignment of Pro unlocks them.
- Permission map is table-tested: each (platform role × permission slug) pair asserts allow/deny against the canonical matrix (Pest dataset over `RolesPermissionsSeeder`).

#### Public Page Rendering (SEO)
- Home, directory index, company profile, species catalog, and species SEO pages each return HTTP 200.
- Each page renders the correct `<title>` and meta description from page/entity data (no hardcoded strings; i18n keys resolve).
- Company profile and species pages emit valid JSON-LD (`Organization`/`Product`-style structured data) matching the entity.
- Canonical/`og:url` tags are built from `APP_URL` (config-driven), never a hardcoded domain.
- A `draft`/`suspended`/`archived` company returns 404 (not publicly listed); only `verified` (and policy-allowed) companies render.
- Directory full-text search + Eloquent filters return expected companies/species (Scout DB driver path).

#### Sitemap & Robots
- `/sitemap.xml` returns 200, valid XML, and includes verified companies + published species/pages, excluding non-public companies.
- Sitemap URLs are absolute and derived from `APP_URL`.
- `/robots.txt` returns 200 and references the sitemap URL.

#### Document-Expiry Reminder Jobs (Scheduler)
- With `travelTo`, the expiry job dispatches reminders exactly at the configured thresholds (`90` / `60` / `30` days before expiry, and `expired`) for badges (`valid_until`) and documents (`expiry_date`).
- Threshold calculation is table-tested over many `valid_until`/`expiry_date` vs "today" combinations (Pest dataset).
- Job is idempotent: re-running on the same day for the same threshold does not double-send — asserted via the `document_reminder_logs` ledger (UNIQUE on `company_document_id, threshold`); a row is written on first send and the duplicate run inserts nothing and sends nothing.
- Expired badges/documents trigger the `expired`-threshold notification and the badge is marked `expired`.
- Job enqueues notifications on the Redis queue (`Queue::fake()` assertion) rather than sending inline.

---

## Build Sequence (Phased)

This section sequences MVP delivery into five dependency-ordered phases (A-E). Each phase lists concrete, verifiable tasks; later tasks may depend on earlier ones within and across phases. Every phase ends with a **Key Deliverable** and a **Demoable** statement describing what can be shown to a stakeholder. Table and column names referenced here are governed by the Data Model section (single source of truth); enum values match the locked canonical lists.

Conventions for this section:
- Each task is prefixed with a stable ID (`A1`, `B3`, etc.) for cross-referencing in the implementation plan.
- "Verify" notes give the acceptance check (a passing Pest test, a manual demo step, or a CLI assertion).
- Tasks assume the locked stack: Laravel 12.x / PHP 8.3, PostgreSQL, Redis (predis), TALL public site, two Filament 3 panels, Pest.

---

### Phase A — Foundation

Goal: a bootable, tested, multi-panel Laravel app with auth scaffolding, RBAC, audit logging, and base styling. No domain features yet.

- **A1. Create project & pin versions.** `composer create-project laravel/laravel cameroontimberhub` targeting Laravel 12.x; confirm PHP 8.3 in `composer.json` `platform` and CI. Commit lockfile.
  *Verify:* `php artisan --version` reports 12.x; `composer check-platform-reqs` passes.
- **A2. Environment & config scaffolding.** Define `.env.example` with `APP_URL` (canonical base, no hardcoded domain), PostgreSQL connection (`DB_CONNECTION=pgsql`), Redis (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`), mail, and a private filesystem disk named **`documents`** (config-driven, local for MVP, S3-swappable). Set `predis/predis` as Redis client. Document required keys in README.
  *Verify:* `php artisan config:show database` and `cache` resolve to pgsql/redis; `config:show filesystems` shows a `documents` disk with `visibility=private`; app boots against an empty Postgres database.
- **A3. Install core packages.** Require: `livewire/livewire ^3`, `filament/filament ^3`, `spatie/laravel-permission`, `spatie/laravel-activitylog`, `spatie/laravel-sitemap`, `laravel/scout` (DB driver), `pestphp/pest` + `pestphp/pest-plugin-laravel`. Publish each vendor config and migration set.
  *Verify:* `composer install` clean; all published migrations present under `database/migrations`.
- **A4. Tailwind + base layout.** Configure Tailwind (via Vite), define the public layout (`resources/views/layouts/app.blade.php`) with header/footer partials, a content slot, and SEO meta partial placeholder. Wire Alpine via Livewire. No domain markup yet.
  *Verify:* `npm run build` succeeds; a placeholder home route renders the layout with Tailwind classes applied.
- **A5. Register two Filament panels on a single `web` guard.** Create `AdminPanelProvider` at path `/admin` (platform staff) and `DashboardPanelProvider` at path `/dashboard` (company users). BOTH panels authenticate against the single default `web` guard over the `users` table — no per-panel guards, no `admin`/`exporter` guards, no separate cookies. Gate access with `canAccessPanel()`: `/admin` requires a platform staff spatie role (`super_admin`/`admin`/`verification_officer`/`content_manager`); `/dashboard` requires company membership (a `company_user` row). Panels remain isolated by `->id()`, branding, and login routes but share the session/guard. No Filament tenancy (single-company dashboard for MVP). Public site stays custom TALL, not Filament.
  *Verify:* `/admin/login` and `/dashboard/login` each render their own login; a staff-roled user reaches `/admin` but is denied `/dashboard` without a `company_user` row, and a company user reaches `/dashboard` but is denied `/admin` (403/redirect via `canAccessPanel()`) — both decided on the one `web` guard (Pest).
- **A6. Localization structure.** Set `APP_LOCALE=en`; create `lang/en/` namespaces (`directory`, `verification`, `rfq`, `validation` overrides). Establish the rule: no hardcoded user-facing strings — all via `__()`/translation keys. French (`/fr` locale prefix) deferred to V2 but folder structure ready.
  *Verify:* a sample Blade string resolves via `__('directory.heading')`; static check/grep convention documented.
- **A7. Spatie permission + activitylog setup.** Run permission and activitylog migrations. Enable activitylog with a configured `activity_log` connection. Add `HasRoles` to the `User` model (default `web` guard — spatie roles/permissions live under the single default guard, no per-guard duplication).
  *Verify:* permission and `activity_log` tables exist; a manual `activity()->log()` writes a row.
- **A8. Canonical roles & permissions seeder.** `RolesAndPermissionsSeeder` (default guard) creating the canonical platform staff roles `super_admin`, `admin`, `verification_officer`, `content_manager` (plus seedable-but-unused-in-MVP `sales_officer`, `finance_officer`, `support_officer`) and the canonical permission slugs: `companies.view`, `companies.manage`, `companies.suspend`, `documents.review`, `verification.review`, `badges.issue`, `badges.revoke`, `species.manage`, `rfqs.triage`, `rfqs.route`, `pages.manage`, `plans.manage`, `users.manage`, `audit.view`. Apply the exact role→permission matrix:
  - `super_admin`: ALL permissions.
  - `admin`: `companies.view`, `companies.manage`, `documents.review`, `verification.review`, `badges.issue`, `badges.revoke`, `species.manage`, `rfqs.triage`, `rfqs.route`, `pages.manage`, `audit.view` (NOT `companies.suspend`, `plans.manage`, `users.manage`).
  - `verification_officer`: `companies.view`, `documents.review`, `verification.review`, `badges.issue`, `badges.revoke`, `audit.view`.
  - `content_manager`: `companies.view`, `species.manage`, `pages.manage`.
  These spatie roles govern platform staff only; company-side access inside `/dashboard` is governed by `company_user.role` (`owner`/`manager`/`member`), not spatie.
  *Verify:* Pest test asserts each canonical role exists with exactly the matrix permissions above after `db:seed` (and that `companies.suspend`/`plans.manage`/`users.manage` are absent from `admin`).
- **A9. Audit middleware.** Middleware (or a global activitylog subscriber/tap) recording authenticated actor, route, and IP/user-agent into the activity `properties` JSONB on state-changing requests in both panels; redact sensitive fields. Register on `admin` and `dashboard` middleware stacks (both on the `web` guard).
  *Verify:* Pest test: a write action in a panel produces an activity row with causer + IP/user-agent inside `properties`.
- **A10. Base test harness.** Configure Pest with a Postgres test database, `RefreshDatabase`, factories for `User`, and a CI workflow running `php artisan test`. Add architecture/smoke test asserting app boots and both panels respond.
  *Verify:* `./vendor/bin/pest` green; CI passes on a clean checkout.

**Key Deliverable:** A bootable Laravel 12 app with Postgres+Redis, two Filament panels on a single `web` guard with `canAccessPanel()` role/membership gating, canonical RBAC roles/permissions seeded, audit logging active, Tailwind layout, and a green Pest suite.
**Demoable:** Log in to `/admin` (staff role) and `/dashboard` (company member) as different users on the one `web` guard; show `canAccessPanel()` enforcement, an audit entry, and the styled public placeholder homepage.

---

### Phase B — Directory & Species

Goal: the public-facing directory and species catalog, company-user-editable company profiles, admin species management, and SEO scaffolding/sitemap. Verification badges are stubbed (display-only) until Phase C.

- **B1. Core directory migrations.** Migrations for `companies` (`slug` unique+indexed; name columns `legal_name` and `trade_name` — NO `name` column; `status` enum check constraint with canonical values `draft|pending|verified|suspended|rejected|archived`; image columns `logo_path`/`cover_path` as public-disk paths; structured/SIGIF-capture fields; soft deletes), `species` (slug unique, `image_path` on public disk), pivot `company_species` (alphabetical singular_singular), and the relational `company_contacts`, `company_export_markets`, and `company_user` (pivot `role` enum check `owner|manager|member`) tables — contacts/export-markets/species live in relations, NOT JSONB columns on `companies`. Add GIN index for full-text search columns on `companies` and `species`.
  *Verify:* `migrate:fresh` succeeds; check constraints reject invalid `status` and `company_user.role` enum values (Pest).
- **B2. Eloquent models + casts.** `Company`, `Species` models with PHP enum cast for `status`, `SoftDeletes`, slug generation, and `company_species`/`company_contacts`/`company_export_markets`/`company_user` relations. Add a `name` accessor on `Company` returning `trade_name ?: legal_name` for display. Add a `CompanyStatus` PHP enum matching the DB constraint.
  *Verify:* Pest: factory creates a company with a cast enum; `name` accessor returns trade-then-legal fallback; invalid enum assignment throws.
- **B3. Scout DB full-text search.** Configure Laravel Scout DB driver on `Company` (and optionally `Species`); define searchable columns backed by Postgres full-text + GIN index. No Meilisearch.
  *Verify:* a seeded company is returned by `Company::search('keyword')`.
- **B4. Admin Species CRUD (Filament).** `SpeciesResource` in the admin panel: create/edit/list/delete species with slug, common/scientific names, description, SEO fields. Gated by the `species.manage` permission.
  *Verify:* a user with `species.manage` can create a species in `/admin`; appears in DB and list table; a user without it is denied.
- **B5. Company profile resource (Filament dashboard).** A `CompanyProfile` resource (or page) in the `/dashboard` panel scoped to the authenticated user's single company (via their `company_user` row): edit profile fields, species offered (attach `company_species`), contacts (`company_contacts`), export markets (`company_export_markets`), structured SIGIF-capture fields. Edit rights gated by `company_user.role` (owner full, manager limited, member read). Saving keeps `status` in `draft`/`pending` per onboarding rules (full onboarding in Phase C).
  *Verify:* a company user edits only their own company; cross-company access blocked; a `member` cannot write (Pest policy test).
- **B6. Public directory listing (TALL).** Livewire-powered directory index: paginated, server-rendered company cards, filters (species, region, verified flag) and keyword search via Scout. Only publicly listable companies (e.g. `verified`/eligible statuses) shown; visibility scopes use relations (e.g. `whereHas('contacts')`).
  *Verify:* visiting `/directory` lists seeded verified companies; filtering by species narrows results; renders without JS (SSR).
- **B7. Public company profile pages.** Server-rendered `/companies/{slug}` profile (display name via the `name` accessor) with company info, species, and a verification badge placeholder using legally-safe wording ("Documents reviewed based on information submitted by the company", "Verification date", "Valid until", "Buyers should conduct final due diligence before transaction." — never "guarantee"). Suspended/archived/draft return 404.
  *Verify:* verified company profile renders at its slug; non-public statuses 404 (Pest).
- **B8. Species SEO pages (programmatic).** Server-rendered species catalog pages with a list of companies offering that species — programmatic SEO templates driven by `species` data. The programmatic route `/exporters/{species}-cameroon` uses a plain string route param (suffix-stripped), not route-model binding.
  *Verify:* each seeded species has a reachable page listing related companies; the suffix-stripped param resolves the species.
- **B9. SEO scaffolding.** Per-page `<title>`, meta description, canonical (built from `APP_URL`), Open Graph tags, and JSON-LD (Organization/Product where apt) via a reusable SEO Blade component fed from model SEO fields. No hardcoded domain.
  *Verify:* view-source on directory/profile/species pages shows correct canonical + meta derived from `APP_URL`.
- **B10. Sitemap generation.** `spatie/laravel-sitemap` command/job generating `sitemap.xml` (base URL from `APP_URL`) covering directory, company profiles (public only), and species pages; scheduled to regenerate. `robots.txt` references it.
  *Verify:* `php artisan sitemap:generate` (or job) produces a valid sitemap including expected URLs.

**Key Deliverable:** A browsable, SEO-ready public directory + species catalog backed by Postgres full-text search, with company users editing their own profiles in `/dashboard` (gated by `company_user.role`) and admins managing species in `/admin`.
**Demoable:** Browse `/directory`, filter by species, open a company profile and a species SEO page; show generated `sitemap.xml`; show a company owner editing their profile and an admin adding a species.

---

### Phase C — Onboarding & Compliance

Goal: full company registration/onboarding, secure document upload via an abstracted `DocumentService` on the private `documents` disk, per-document admin review, verification requests, multi-type badge issue/revoke/expiry, and scheduled expiry reminders.

- **C1. Company registration.** Public registration (TALL) creating a `User` on the single `web` guard + an associated `Company` in `status = draft`, plus a `company_user` row with `role = owner`; email verification on the user account. (No spatie role assigned — platform staff roles are separate; dashboard access is granted by the `company_user` membership.)
  *Verify:* registering creates user + company in `draft` + an `owner` `company_user` row; verification email dispatched (Pest fake mail).
- **C2. Onboarding flow.** A guided multi-step onboarding (dashboard) collecting required profile + compliance fields and prompting document uploads; on completion the company moves `draft -> pending`. Incomplete onboarding gates submission.
  *Verify:* completing required steps transitions status to `pending`; missing required field blocks submission (Pest).
- **C3. Document storage migrations.** `company_documents` table: FK `company_id`; `document_type_id` (bigint FK → `document_types`, no inline type-enum string); `status` enum check (`pending|approved|rejected|needs_correction`, default `pending`); `visibility` enum check (`private|admin_only|buyer_visible|public`, **default `private`**); `storage_path` (varchar) plus `disk` (varchar, default `'documents'`); original filename, mime, size; `issue_date` (date, null) and `expiry_date` (date, null); soft deletes. Add the `document_types` lookup table and a `document_access_logs` table (who/when/which document).
  *Verify:* `migrate:fresh` succeeds; enum check constraints enforced; `visibility` defaults to `private` and `disk` defaults to `documents` (Pest).
- **C4. DocumentService + private `documents` disk.** A `DocumentService` abstraction handling store/retrieve/delete and **signed temporary URLs** against the **private `documents` disk** (config-driven, local for MVP, designed so S3 is a later config swap). Documents are NEVER written to the public disk; `storage_path` + `disk` identify the file.
  *Verify:* uploaded file is not web-reachable directly; a signed URL grants time-limited access; the service records `storage_path` + `disk='documents'` and has no disk-specific leakage (Pest).
- **C5. Document upload (dashboard).** Company user uploads documents (typed via `document_type_id`) with validation (mime/size); each stored via `DocumentService` on the `documents` disk, created `status = pending`, default `visibility = private`. Re-upload supported for `needs_correction`.
  *Verify:* a company user uploads a PDF; row created `pending` with `visibility=private` and `disk=documents`; file stored privately (Pest).
- **C6. Document access logging.** Every document view/download (admin or signed-URL access) writes a `document_access_logs` row via the service.
  *Verify:* accessing a document creates an access-log entry with actor + timestamp (Pest).
- **C7. Verification request model + flow.** `verification_requests` table/model with columns `company_id`, `type`, `status` enum (`pending|in_review|approved|rejected`), `assigned_to` (FK users null), `decided_by` (FK users null), `decided_at` (null), `decision_notes` (text null), `document_snapshot` (jsonb null), `requested_badges` (jsonb null), timestamps. Use `created_at` as submission time and `decided_at` as review time (no `submitted_at`/`reviewed_at`). Submitting onboarding (or an explicit action) creates a `pending` request.
  *Verify:* submission creates a `pending` verification request referencing the company's documents via `document_snapshot`.
- **C8. Admin review queue (Filament).** Admin/verification-officer queue listing `pending`/`in_review` verification requests; reviewers open a request, view documents via signed URLs, and set **per-document** status (`approved|rejected|needs_correction`) with notes. Request status advances (`pending -> in_review -> approved|rejected`), stamping `assigned_to`/`decided_by`/`decided_at`. Gated by `documents.review` + `verification.review`; all actions audited.
  *Verify:* a `verification_officer` approves all documents and the request; company eligible for badge; rejecting a document sets `needs_correction` and notifies the company (Pest).
- **C9. Multi-type verification badges.** `verification_badges` table/model supporting MULTIPLE badge types: `badge_type` varchar + CHECK in `verified_company|verified_exporter|sigif_registered|legal_timber_supplier|export_ready|cites_approved|sustainability_profile|premium_member`; `status` enum (`active|revoked|expired`); `issued_at` (timestamp), `valid_until` (date, null), `verified_by` (FK users), `verification_notes` (text null), `supporting_document_id` (FK company_documents null), `is_public` (bool default true), `reference_code` (varchar unique). Partial UNIQUE `(company_id, badge_type) WHERE status = 'active'` — one active badge per type per company, multiple types allowed. Approving a verification request **issues** the requested `active` badge(s) (gated by `badges.issue`); admin can **revoke** (-> `revoked`, gated by `badges.revoke`); expiry handled by `valid_until`. On approval, company `status` may move to `verified`. Public profile shows badges with legally-safe wording only (never "guarantee").
  *Verify:* approval issues `active` badge(s) of the requested `badge_type` and flips company to `verified`; a second active badge of the same type is rejected by the partial unique; revoke marks the badge `revoked`; profile wording matches legal constraints (Pest + view assertion).
- **C10. Expiry reminder job + scheduler.** Queued job scanning badges (`valid_until`) and documents (`expiry_date`) approaching expiry at thresholds **90 / 60 / 30** days before, plus **`expired`**. Each reminder is recorded idempotently in `document_reminder_logs` (`company_document_id` FK, `threshold` varchar CHECK in `'90'|'60'|'30'|'expired'`, `sent_at`, UNIQUE `(company_document_id, threshold)`) so each threshold fires at most once. Emails go to the company (and flag admins); badges past `valid_until` transition to `expired` and drop the public "verified" presentation. Registered in the scheduler (e.g. daily) with the queue worker processing it.
  *Verify:* a badge/document dated to expire soon triggers a reminder at the correct threshold and writes one `document_reminder_logs` row; re-running the job does not duplicate it (UNIQUE); a past-due badge becomes `expired` and the profile no longer shows active verification (Pest time-travel + mail fake).

**Key Deliverable:** End-to-end company onboarding with private document upload on the `documents` disk, per-document admin review, multi-type badge issuance/revocation/expiry, access logging, and idempotent automated expiry reminders (90/60/30/expired).
**Demoable:** Register a company (owner), complete onboarding, upload documents (private disk); as a verification officer review and approve them; show the issued multi-type badge(s) on the public profile with compliant wording; revoke a badge; run the reminder job to expire a badge and watch the profile update.

---

### Phase D — RFQ & Leads

Goal: public buyer intake (RFQ + general inquiry) with email verification and anti-spam, admin triage and routing to companies, and a light company lead inbox. No buyer accounts.

- **D1. RFQ & inquiry migrations.** `rfqs` table: `reference_code` (unique, human-friendly), buyer contact fields (`buyer_name`, `buyer_company` null, `buyer_email`, `buyer_phone` null), `buyer_country_code`, `destination_country_code`, `incoterm`, `target_amount` `decimal(14,2)` null + `target_currency` null (XAF/USD/EUR/GBP/CNY), `deadline` (date null), `shipping_port` (null), `notes` (text null), `status` enum (`new|in_review|approved|rejected|spam|closed`, default `new`), `visibility` enum (`public|private|admin_assisted`), `email_verified_at` (null), `ip_address` (null), `source`, `spam_score` (int default 0), `is_spam` (bool default false), `attachments` (jsonb null), timestamps, soft deletes (NO `specs`/`risk_score`/`risk_flags`). `rfq_items` table: `rfq_id` FK, `species_id` (FK null), `species_text` (null), `form` (`logs|sawn|veneer|plywood|other`), `grade` (null), `dimensions` (null), `quantity` (decimal), `unit` (`m3|ton|pcs|container`), `moisture_content` (null). `rfq_company` routing pivot: `rfq_id` FK, `company_id` FK, `status` enum (`sent|viewed|responded|declined`, default `sent`), `routed_by` (FK users), `routed_at`, timestamps, UNIQUE `(rfq_id, company_id)`. `leads` table with `status` enum (`new|contacted|won|lost|dormant`). `company_inquiries` table (direct profile contact) per Data Model.
  *Verify:* `migrate:fresh` succeeds; all enum check constraints enforced; `target_amount` is `decimal(14,2)`; `rfq_company` UNIQUE `(rfq_id, company_id)` holds (Pest).
- **D2. Public RFQ + inquiry forms (TALL).** Server-rendered RFQ form (species/quantity via `rfq_items`, budget, buyer fields incl. `buyer_country_code`, notes) and a lighter general inquiry form (`company_inquiries`). RFQ created in `status = new`, `email_verified_at` null, with a generated `reference_code`. Strings via translation keys.
  *Verify:* submitting creates an `rfqs` row in `new` with a `reference_code` and at least one `rfq_items` row, `email_verified_at` null (Pest).
- **D3. Email verification for submissions.** Double opt-in: on submit, send a signed verification link; only RFQs/inquiries with `email_verified_at` set enter triage. Unverified records expire/are purged on a schedule.
  *Verify:* submission email-verify link sets `email_verified_at` and makes the record triage-eligible (Pest signed-URL test).
- **D4. Anti-spam.** Honeypot field, timing check, rate limiting (Redis), and a content heuristic feeding `spam_score`; suspected spam set `is_spam = true` and `status = spam`, excluded from routing. Suspicious activity logged to `suspicious_events` (`event_type` + `context`). CAPTCHA hook point documented (config-driven).
  *Verify:* a honeypot-tripped or rate-limited submission gets `is_spam=true`/`status=spam`, raises `spam_score`, writes a `suspicious_events` row, and is never routed (Pest).
- **D5. Admin RFQ triage (Filament).** Admin queue of verified RFQs/inquiries; reviewers move `new -> in_review -> approved|rejected|spam|closed`, edit/normalize fields, and view buyer details. Gated by `rfqs.triage` and audited.
  *Verify:* a reviewer with `rfqs.triage` transitions an RFQ through statuses; transitions audited (Pest).
- **D6. Routing to companies.** On approval, admin routes an RFQ to one or more matching companies, creating `rfq_company` rows in `status = sent` with `routed_by`/`routed_at` (matching by species/region with manual override). Gated by `rfqs.route`. Notifies routed companies by email.
  *Verify:* routing creates `rfq_company` rows `sent` (respecting UNIQUE) and dispatches company notifications (Pest mail fake).
- **D7. Company lead inbox (dashboard).** A `/dashboard` inbox listing RFQs routed to the user's company; viewing flips `rfq_company.status` `sent -> viewed`; the user can mark `responded` or `declined`. A matching `leads` row tracks pipeline `status` (`new|contacted|won|lost|dormant`). Buyer contact details respect visibility and plan gating (light handling here; full gating in Phase E).
  *Verify:* a company user opens a routed RFQ (status -> `viewed`), marks `responded`; a `leads` row reflects the interaction (Pest).

**Key Deliverable:** A spam-resistant, email-verified public RFQ/inquiry pipeline (rfqs + rfq_items) that admins triage and route to companies via `rfq_company`, surfaced in a company lead inbox with lead-status tracking — all without buyer accounts.
**Demoable:** Submit a public RFQ, click the verification email link, watch it appear in admin triage; approve and route it to a company; log in as that company and see the lead, open it (status flips to viewed), and mark it responded.

---

### Phase E — Plans & Polish

Goal: plans-as-data with feature gating and manual assignment, a public pricing page, dashboard widgets, a full SEO pass, a security-hardening pass, and a complete green Pest run.

- **E1. Plans & subscriptions data.** `plans` table (name, slug, price `amount` `decimal(14,2)` + `currency`, JSONB `features`/limits) and `subscriptions` table (FK `company_id`, FK `plan_id`, `status` enum `active|expired|cancelled`, `starts_at`, `ends_at`). Seed baseline plans. No payment gateway.
  *Verify:* `migrate:fresh` + seed produces plans; subscription enum constraint enforced (Pest).
- **E2. Feature gating.** A `PlanGate`/policy layer reading the active subscription's `features`/limits to gate capabilities (e.g. number of routed leads visible, buyer contact reveal, profile richness, document slots). Default/free tier when no active subscription.
  *Verify:* a company on a limited plan is blocked beyond a feature limit; upgraded plan unlocks it (Pest).
- **E3. Manual plan assignment (Filament admin).** Admin action (gated by `plans.manage`) to assign/change a company's plan, creating/updating a `subscriptions` row (`active`, with `starts_at`/`ends_at`); changing status to `expired`/`cancelled` supported. Audited.
  *Verify:* an admin with `plans.manage` assigns a plan; company's gated features change accordingly; action audited (Pest).
- **E4. Public pricing page (TALL).** Server-rendered `/pricing` rendering plans-as-data (features/limits, price + currency) with a "contact to upgrade" CTA (manual assignment, no checkout). SEO meta included.
  *Verify:* `/pricing` lists seeded plans with correct prices/features from data.
- **E5. Dashboard widgets.** Company `/dashboard` widgets (profile completeness, verification/badge status + `valid_until` expiry, lead counts by `rfq_company.status`, plan/usage vs limits). Admin `/admin` widgets (pending verification requests, RFQ triage backlog, expiring badges/documents, recent audit activity).
  *Verify:* widgets render correct counts against seeded data (Pest/Livewire test).
- **E6. Full SEO pass.** Audit every public route for canonical (`APP_URL`-driven), unique titles/meta, JSON-LD correctness, sitemap coverage (directory/profiles/species/pricing), `robots.txt`, and clean slugs/redirects. Confirm no hardcoded domain anywhere; pages body content stored in the `data` JSONB column with optional `canonical_url`.
  *Verify:* automated check asserts canonical/meta present on all public routes and sitemap includes them; grep finds no hardcoded domain.
- **E7. Security hardening pass.** Enforce HTTPS/secure cookies, CSRF on all forms, headers (CSP/HSTS/X-Frame-Options) via middleware, signed-URL TTL review for documents, authorization coverage on every resource (single `web` guard with `canAccessPanel()`; spatie permissions for staff; `company_user.role` for company users; no IDOR; cross-company isolation), rate limits on public endpoints, mass-assignment guards, and verification of `documents`-disk inaccessibility. Confirm audit coverage (incl. IP/user-agent in `properties`) on all state-changing admin/dashboard actions.
  *Verify:* Pest policy/security tests pass (cross-company access denied, documents not publicly reachable, spam/rate limits active); security headers present in responses.
- **E8. Complete Pest run + release gate.** Full suite green across all phases (feature, policy, Livewire, scheduler/job, mail) in CI; add coverage for any gaps surfaced during E6/E7. Tag the MVP build.
  *Verify:* `./vendor/bin/pest` fully green in CI on a clean checkout; coverage thresholds (if configured) met.

**Key Deliverable:** Plans-as-data with feature gating and manual admin assignment, a public pricing page, role-appropriate dashboard widgets, a hardened and fully SEO-audited site, and a complete passing Pest suite — MVP-complete.
**Demoable:** Show the `/pricing` page; as admin assign a plan to a company and demonstrate a gated feature unlocking; show company and admin dashboard widgets; present the full green Pest run and the SEO/security audit results.

---

### Cross-Phase Dependency Summary

- **A precedes all** (two panels on one `web` guard, `canAccessPanel()` gating, canonical RBAC, audit, test harness are prerequisites).
- **B depends on A**; introduces `companies`/`species` (and `company_user` membership) that **C, D, E** all build on.
- **C depends on B** (companies/profiles) — adds documents on the `documents` disk, verification, multi-type badges; badge display in B7 is stubbed until C9.
- **D depends on B** (companies/species for routing via `rfq_company`) and benefits from C (verified companies to route to) but can be built in parallel with C after B.
- **E depends on B/C/D** (gates leads from D, badges/profile richness from C, directory from B) and closes with system-wide SEO/security/test passes.

---

## Deferred to V2+ (with rationale)

These capabilities are intentionally excluded from the MVP to keep the first release shippable and focused on the acquisition + trust loop (directory → verification → SEO → RFQ). They are sequenced roughly per the platform vision document's V2–V4.

| Capability | Target | Why deferred |
|---|---|---|
| Buyer accounts & dashboards (saved suppliers, RFQ tracking, quote comparison) | V2 | MVP captures buyers via public forms; accounts add auth/UI surface without changing the core acquisition loop |
| Inventory management (logs/sawn/veneer, grades, dimensions, availability) | V2 | Large module; directory + RFQ prove demand first |
| Quotation builder + PDF generation | V2 | Requires inventory/pricing model maturity; admins facilitate manually in MVP |
| Invoicing (proforma/commercial, multi-currency, numbering) | V3 | Downstream of quotations; finance-grade concern |
| Full CRM pipeline (deals, tasks, follow-ups beyond lead status) | V2/V3 | MVP ships a light lead inbox; richer CRM after adoption |
| Commission tracking | V3 | Needs deal/quote data to compute against |
| Broker / agent module (mandates, lead attribution) | V3 | Separate user type and trust model |
| Reviews & reputation (verified-buyer, post-deal, moderated) | V3 | Requires the completed-deal signal that only exists after quotes/invoices |
| Live payment gateway (Stripe / local XAF) | V2 | MVP assigns plans manually; billing integration is a discrete project |
| Meilisearch / Typesense | V2 | Postgres full-text covers MVP search volume |
| SIGIF integration | Phased (fields in V1; semi-automated checks V3+) | Avoid coupling the MVP to government system access; capture structured SIGIF fields now |
| Public / partner API | V3 | Internal-only until enterprise ERP demand is real |
| Multi-country expansion, mobile app, ERP add-on, traceability, sustainability/carbon records | V4 | Post-product-market-fit scale concerns |

**i18n note.** The MVP ships English-only content but is built i18n-structured (Laravel localization, no hardcoded user-facing strings), so French (Cameroon's other official language) can be added in V2 without rework — including a future `/fr` locale-prefixed URL strategy described in the SEO section.

**SIGIF note.** Per the vision document's phased strategy, the MVP only **captures** structured SIGIF-related fields (registration number, permit number, document reference, expiry) and verifies them **manually**. No automated or official SIGIF integration is attempted until it is legally and technically sanctioned.
