# ADR 0001 — Keep `company_documents` / `order_documents` as separate tables (for now)

**Status:** Accepted · **Date:** 2026-09-10 · **Supersedes:** the open action in `docs/GAP_PLAN.md` item 0.1b ("the actual 24+4 consumer migration")

## Context

The platform has two document storage models:

1. The **polymorphic `Document` store** (`documents` table, `owner_type`/`owner_id`, `App\Models\Concerns\HasDocuments`) — added in gap-plan Phase 0. Every **new** document-bearing entity uses it: `Species`, `Product` (Batch B2), `Vehicle` and `Driver` (fleet registry), timber-lot compliance packs.
2. The **legacy dedicated tables** — `company_documents` (verification paperwork: RCCM, export permits, forestry legal docs) and `order_documents` (export doc checklist per order). These predate the polymorphic store and carry their own mature, tested machinery:
   - Filament resources in **both** panels (`app/Filament/Resources/CompanyDocuments/`, `app/Filament/Exporter/Resources/CompanyDocuments/`, order document flows in `OrderLifecycleController`)
   - `app/Actions/Documents/` — `UploadCompanyDocument`, `ApproveDocument`, `RejectDocument`, `RequestDocumentCorrection`
   - `DocumentApproved` / `DocumentRejected` events, `ExtractDocumentFieldsJob` (AI field extraction), `DocumentDownloadController` / `OrderDocumentDownloadController` (signed downloads + access logs)
   - the exporter **onboarding checklist** (`OnboardingChecklist` page counts `company_documents` by type)
   - `CompanyDocument` carries `document_type_id` (FK to `document_types`), `visibility`, `sigif_fields` — SIGIF-integration columns with no current equivalent behaviour on `documents`

`documents` was **extended** additively during Phase 0.1b to hold `document_type_id` / `visibility` / `sigif_fields`, `DocumentReminderLog` was made polymorphic, `SendDocumentExpiryReminderJob` already logs for both, and an **idempotent, upload-order-correct backfill command exists and is tested**: `documents:backfill-from-legacy` (`App\Console\Commands\BackfillDocumentsFromLegacyTables`).

`docs/GAP_PLAN.md` 0.1b tracks the remaining work — migrating ~22 consumer files (2 Filament resource trees, 4 actions, 2 events, 2 jobs, 2 download controllers, the onboarding checklist, the dispute-evidence resource, policies, observers) off the legacy tables and dropping them.

## Decision

**Do not perform the consumer migration now.** The two legacy tables and their consumers stay as-is. New consumers continue to use the polymorphic `Document` store.

## Rationale

- **Risk vs. value.** The legacy subsystems are in production, fully tested, and working. The migration touches ~22 files across two admin panels, the onboarding flow (a gate on supplier verification), signed-download authorization, and AI extraction. A regression in any of these is a customer-facing incident on the platform's trust layer. The upside is code-consistency, not a fixed bug.
- **The migration stays cheap to do later.** The schema is already reconciled (all three legacy-only columns exist on `documents`), the reminder log is already polymorphic, and the backfill command is written and tested. Nothing about deferring makes the eventual cutover harder.
- **No functional gap.** `§3.2` ("product documents with status") is satisfied by Batch B2 using the polymorphic store directly. Expiry reminders already cover both stores. There is no user-visible behaviour that the split prevents.
- **The split is bounded and documented, not creeping.** New entities never touch the legacy tables; the boundary is clear.

## Consequences

- `app/Models/CompanyDocument.php` and `app/Models/OrderDocument.php` remain. `grep CompanyDocument` will keep returning ~22 hits.
- The `documents:backfill-from-legacy` command remains dormant but maintained (a test exercises it).
- Any **new** document-bearing feature MUST use `HasDocuments` + the `documents` table — never add a column to `company_documents` / `order_documents` or a new dedicated table.
- `docs/GAP_PLAN.md` 0.1b is re-scoped from "open" to "deferred by ADR 0001". Revisit when: (a) a change is needed that would touch a large fraction of the legacy consumers anyway, or (b) the SIGIF integration is built and needs the unified model, or (c) the team has a low-risk window to do the cutover as its own project (estimated ~6 engineer-days, one consumer group per PR: verification resources → order-doc flow → actions/events → jobs → drop tables).
