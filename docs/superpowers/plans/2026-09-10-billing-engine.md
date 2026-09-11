# Billing Engine — Proposal & Phased Plan

> Companion to `docs/PRICING_SPEC.md` (the commercial source of truth) and `docs/GAP_PLAN.md` item 0.9b.
> **Date:** 2026-09-10 · **Status:** proposal with recommended decisions (§7 — proposed, owner to confirm/adjust). Ready to execute via `superpowers:subagent-driven-development` once Phase 0 is underway.

**Bottom line:** the billing engine is **~65% built**. The payment gateways (incl. MTN MoMo + Orange Money), plan catalogue, subscription model, entitlement engine and the payment→outbox event chain already exist. Remaining: (a) two merchant-account applications + a PayPal Business account [Phase 0, business], (b) ~**45 engineer-days / 9 weeks** of work — phased so **domestic subscriptions on MoMo/Orange Money and USD plans on PayPal go live at the end of Phase 1 (~2.5 weeks)**. Gateway API credentials are entered and rotated in `/admin` (two-person + 2FA, like the AI keys) — §8.

---

## 1. The commercial model (recap from `PRICING_SPEC.md`)

Multiple coordinated revenue streams, not one subscription:

| Stream | Customer | Mechanism | Status |
|---|---|---|---|
| **Subscriptions** | buyers, suppliers, dealers, exporters | fixed monthly/annual plans, XAF domestic / USD international | catalogue seeded (§26); no self-serve purchase yet |
| **Marketplace commission** | parties to a protected trade | 2–5 % of order value, plan-tiered, capped (§15) | not built |
| **Verification / Compliance / Traceability / Data** | suppliers, exporters, enterprises | annual fee / monthly workspace | plans seeded; sold like a subscription |
| **Enterprise / API** | large buyers, institutions | negotiated contract, transparent floor | manual assignment works today |
| **Trade services, logistics, promotion** | buyers, exporters, advertisers | quoted / fixed fees (§16) | not built |

Principles that constrain the build (all from `PRICING_SPEC.md`): prices visible before purchase; subscription ≠ verification status; XAF domestic / USD international with immutable transaction currency; every price versioned with effective dates; historical invoices never change; configurable tax engine (no hard-coded rate); all percentages disclosed pre-commit.

---

## 2. What already exists (do not rebuild)

| Component | File(s) | State |
|---|---|---|
| **Payment gateway interface** | `app/Contracts/PaymentGatewayContract.php` | `initiate()` / `handleWebhook()` / `isConfigured()` — clean |
| **4 gateway implementations** | `app/Services/Payments/{MtnMomo,OrangeMoney,Stripe,PayPal}Gateway.php` | Real. MTN MoMo does the full Collections "Request to Pay" flow (token → `requesttopay` → async webhook match on `X-Reference-Id`). Each **refuses** until real credentials are in config — no fake success. Sandbox/prod env switch. |
| **Checkout entry point** | `app/Http/Controllers/Public/PaymentCheckoutController.php`, `routes/payments.php` + `routes/payments/{provider}.php` | `POST /payments/checkout/{plan}` (auth) → creates a pending `Payment` → delegates to the gateway. Per-provider webhook routes sit outside auth. |
| **Payment model** | `app/Models/Payment.php`, `PaymentProvider` / `PaymentStatus` enums | `markCompleted()` / `markFailed()`, `provider_reference` for webhook matching |
| **Plan catalogue** | `database/seeders/PlanSeeder.php`, `Plan` model, `config` — activatable without deploy (`is_active`) | ~20 plans across 7 segments (`sell`, `buy`, `deal`, `export`, `buy-international`, `verify-comply`, …) — the whole `PRICING_SPEC.md §26` catalogue. `Plan::apiRateLimitTier()`, `Plan::feature()`. |
| **Subscription model + service** | `app/Models/Subscription.php`, `app/Services/SubscriptionService.php`, `app/Domain/Commerce/Commands/AssignSubscription{Command,Handler}.php`, `app/Actions/Subscription/CancelSubscription.php` | `assign()` cancels the prior active sub, mirrors `plan_id` onto the company, logs activity. One active subscription per company. CQRS command exists. |
| **Domain events → outbox** | `app/Domain/Commerce/Events/{PaymentCompleted,SubscriptionActivated}.php`, `RelayOutboxEventsJob::EVENT_MAP` (`payment.completed`, `subscription.activated`) | Both events are registered event types, delivered to webhook subscribers. `RecordPaymentCompletionHandler` records `PaymentCompleted` to the outbox inside the command transaction — **wired for Stripe only**; the other 3 gateways call `Payment::markCompleted()` directly. |
| **Entitlement engine** | `Company::hasFeature()`, gap-plan 0.9c | **Done and enforced** — `max_gallery` (model layer), `leads_receive` (`RfqTriageService`), `featured` (admin warning). Reads real plan data. |
| **Payment config** | `config/payments.php` | Every provider block structured, env-backed, null placeholders. MTN `target_environment=mtncameroon`, currency `XAF`. |
| **Manual assignment (the current "billing")** | admin `/admin` → assign plan; `SubscriptionService::assign()` | Works today. Stays as the enterprise / offline path forever. |

---

## 3. What's missing (the actual work)

| # | Gap | `PRICING_SPEC` ref |
|---|---|---|
| M1 | **Payment → subscription loop.** A completed *plan* payment must auto-activate a `Subscription` for that company/plan with the right term. Today `PaymentCompleted` fires but nothing consumes it to create a sub. | §21 |
| M2 | **Rewire MTN / Orange / PayPal webhooks** to `RecordPaymentCompletionCommand` (like Stripe) so all four emit `PaymentCompleted` to the outbox consistently. | — |
| M3 | **`subscriptions` term columns** — `billing_period` snapshot, `renews_at`, `trial_ends_at`, `grace_until`, `payment_id`, `provider_reference`, `price_amount`/`price_currency` snapshot (so a later price change never rewrites this sub). | §2, §18 |
| M4 | **Invoicing.** `Invoice` + `InvoiceLine` + `CreditNote` models. Auto-generate an immutable invoice on every successful charge; issue a receipt (reuse the existing `Receipt` + `ChainsIntegrity` pattern); credit notes for corrections/refunds (original invoice never changes). PDF via the existing hand-styled certificate-PDF pattern. | §21 |
| M5 | **Tax engine.** `tax_rules` table (jurisdiction, rate, effective dates), a `TaxCalculator` service. Cameroon VAT (19.25 %) as a *configured* default, never hard-coded. Checkout shows subtotal / discount / tax / fees / total separately. Store FX rate + timestamp + provider where conversion happens. | §20 |
| M6 | **Recurring billing & lifecycle.** Card/token on file (Stripe `SetupIntent`; MTN pre-approved payments where the API supports it, else a renewal-reminder + re-pay link). Scheduled renewal charge job. **Dunning state machine:** `active → past_due (grace 7d) → suspended → restored/cancelled`. Payment-failure email sequence. Proration on upgrade (immediate) / downgrade (next cycle). Trials (14-day, opt-in per plan). 30-day price-change notice job. | §18 |
| M7 | **Marketplace commission.** A deterministic, versioned, capped commission line on protected-trade orders — rate from the buyer/supplier plan tier (§15 table), computed on `order.total_amount` (excl. shipping/tax), shown before the order is committed, recorded on the order and the invoice. Not charged on pre-acceptance cancellation. **Explicitly not escrow** — the spec forbids claiming escrow without the safeguarding licence. | §15 |
| M8 | **Coupons / credits / referrals.** `Coupon`, `Credit` models; stacking disabled by default; coupons can't reduce tax; credits are non-cash. | §19 |
| M9 | **Price governance.** `plan_prices` (or `price_versions`) with `effective_from` / `effective_until`, immutable history; "reproducible totals from stored inputs"; pricing-admin RBAC (least privilege). | §23, §27 |
| M10 | **Wire the `api` plan feature** to the now-existing company-authenticated API-key issuance flow (gap-plan 0.9d — the two-person + 2FA issuance flow shipped in the architecture batch, so the surface finally exists). | §22 |
| M11 | **Pricing page → checkout.** The `/pricing` page (`PricingController`, informational today) gets a "Choose plan → pick payment method (MoMo / Orange Money / card) → pay" flow for the segments that launch. Enterprise stays "Talk to us". | §24 |
| M12 | **Admin-panel-managed gateway credentials.** Per owner direction, MTN MoMo / Orange Money (and later Stripe/PayPal) API credentials are entered and rotated in `/admin`, not just `.env`. Mirror the existing `AiSetting` + `AiApiKeyChangeRequest` pattern exactly: a `PaymentSetting` model (one row per provider — `subscription_key` / `api_user` / `api_key` etc. `Crypt::encryptString`-encrypted at rest, an `is_live` toggle, `environment`), `config/payments.php` resolves **DB first, `env()` fallback**, and changing a *live* credential is a two-person + fresh-2FA action (`RequestPaymentCredentialChange` / `ApprovePaymentCredentialChange`, different approver, one-time invite token, `TwoFactorStepUp::isRecentlyVerified()`). Credentials never rendered back after save; shown once. | §23, §20 |

---

## 4. Payment rails — the decision, framed for Cameroon

Stripe/PayPal alone do not serve the domestic (XAF) majority. The rails that matter:

| Rail | Covers | Gateway coded? | To go live |
|---|---|---|---|
| **MTN Mobile Money Cameroon** (MoMo Collections) | domestic XAF — the volume: suppliers, dealers, domestic buyers | ✅ `MtnMomoGateway` | Register at **momodeveloper.mtn.com**, subscribe to the **Collections** product, complete KYB (registered Cameroon business, bank account for settlement), get production `subscription_key` / `api_user` / `api_key`. Set `MTN_MOMO_*` env. |
| **Orange Money Cameroon** (Orange Money Web Payment) | domestic XAF — second rail, meaningful share | ✅ `OrangeMoneyGateway` | Apply through **Orange Cameroun** business / Orange Developer for **Web Payment** (or Orange Money API), get `client_id` / `client_secret` / `merchant_key`. Set `ORANGE_MONEY_*` env. |
| **Stripe** | international USD — exporters, international buyers, enterprise-API | ✅ `StripeGateway` | **Needs a legal entity in a Stripe-supported country** (Cameroon is not supported). Options: (a) an existing group entity elsewhere; (b) Stripe Atlas (US LLC); (c) a **merchant-of-record** — Paddle / Lemon Squeezy — which also handles international sales tax. Decision needed (§7.1). |
| **PayPal** | international USD fallback | ✅ `PayPalGateway` | PayPal Business account (receivable from Cameroon). Lower priority — a fallback, not the primary USD rail. |
| **Bank transfer / offline** | enterprise, institutions, anyone who can't self-serve | ✅ `SubscriptionService::assign()` + a contract record | Admin confirms receipt, assigns the plan, attaches the signed commercial record. No engineering. |

**Recommendation (adopted in §7.1–7.2):** launch **all XAF segments on MTN MoMo + Orange Money** and **all USD segments on PayPal Business**, together, in Phase 1. Enterprise stays on bank transfer + manual assignment. A cleaner USD rail (Stripe / merchant-of-record) is a later add, not a launch dependency. Credentials for every provider are managed in `/admin` (§8), so a provider goes live the moment its keys are approved — no deploy.

---

## 5. Phased plan

Effort is engineer-days for someone who knows this codebase. **Phase 0 is a business action and can start today.**

### Phase 0 — Business actions (0 eng, blocks Phase 1 go-live)
- [ ] Apply for **MTN MoMo Collections** (Cameroon) — KYB, settlement bank account (owner supplies the production credentials into `/admin` once approved — see M12)
- [ ] Apply for **Orange Money Web Payment** (Cameroon)
- [ ] Set up a **PayPal Business** account (USD receivable from Cameroon) — the launch USD rail per §7.1
- [ ] Have an accountant confirm CTH's **TVA** registration / DGI position per §7.3 (engine assumes 19.25 % applies and is switchable — do not block Phase 1 on this)

### Phase 1 — "Plans you can actually buy" (~12 days) → **domestic subscriptions go live**

**Status 2026-09-10: build complete (M12, M1, M2, M3, M11 shipped; commits `d0421f4`, `9adf89d`, `54349c1`, `a…`). Deployed to production. Go-live still gated on Phase 0 business actions — the gateways ship `is_live=false` and refuse checkout (503) until real credentials are approved into `/admin`.**

- [x] **M12: admin-panel gateway credentials.** `PaymentSetting` model + `payment_settings` / `payment_credential_change_requests` tables (per-provider, `credentials` as `encrypted:array`, `environment`, `is_live`, `updated_by`); `config/payments.php` stays env-only (no DB at cache-build); `App\Services\Payments\GatewayCredentials::for($provider)` merges a live `PaymentSetting` row over `config('payments.<provider>')` at request time; all 4 gateways' `isConfigured()`/reads go through it; `RequestPaymentCredentialChange` / `ApprovePaymentCredentialChange` two-person + fresh-2FA actions + one-time invite token (copy `app/Actions/Ai/`); Filament `PaymentSettings` + `PaymentCredentialChangeRequests` resources (values never rendered) under a new `payments.manage` permission + `finance_officer` role.
- [x] M1: `ActivateSubscriptionOnPaymentCompleted` listener (on the `PaymentCompleted` outbox event via `EventServiceProvider`) — a completed plan `Payment` → `SubscriptionService::activateFromPayment($payment)` (sets term from `plan.billing_period`, `renews_at`, snapshots the price *paid*, links `payment_id`, cancels the prior active sub, mirrors `company.plan_id`, emits `SubscriptionActivated`). Idempotent by `payment_id`.
- [x] M2: rewired MTN / Orange / PayPal `handleWebhook()` (and PayPal's browser-return leg) to dispatch `RecordPaymentCompletionCommand` (mirror `StripeGateway`), each with an `isConfigured()` guard (503, no state change) at the top.
- [x] M3: additive `subscriptions` migration — `billing_period`, `renews_at`, `trial_ends_at`, `grace_until`, `payment_id`, `provider_reference`, `price_amount`, `price_currency`. `SubscriptionStatus` gained `Trialing` + `PastDue` (+ `isEntitled()`). `Company::effectivePlan()` resolves the entitled subscription's plan, else the segment Free plan (grace-aware).
- [x] M11: `/pricing` → checkout flow. Card CTA (self-serve plans only; enterprise → "Contact sales") → `GET /billing/checkout/{plan}` method picker (MoMo + Orange for XAF plans, PayPal for USD, each filtered by `GatewayCredentials::isConfigured()`; "not available yet" panel when none) → `POST /payments/checkout/{plan}` → gateway. MoMo poll path: `billing.checkout.pending` "check your phone" page → `…/status` JSON poll → `billing.checkout.success`. `IssueReceiptOnPaymentCompleted` listener issues a hash-chained `Receipt` (idempotent, one per `payment_id`) off the outbox on every gateway path.
- [ ] Renewal-reminder email 7 days before `renews_at` with a re-pay link. On non-payment by `renews_at` + grace, the subscription lapses to the segment's Free plan (no pre-auth charge — see §7.5 for why the trial/renewal model is pull-not-push in a mobile-money market). **→ moved to Phase 2 (M6); not a go-live blocker for accepting the first payments.**
- [x] Tests: full loop per gateway in sandbox; idempotent double-webhook; unconfigured-gateway refusal (503); entitlement flips on activation; a live-credential change requires a second approver + 2FA. (`PaymentSubscriptionActivationTest`, `BillingCheckoutTest`, `PaymentCredentialControlTest`, `SubscriptionTermTest` + the 4 gateway tests.)
- [x] Deployed. Orange & PayPal still land on their own gateway return views (not the unified success page); subscription + receipt fire via webhook regardless — unified return-URL wiring is a Phase 2 polish item.

**Phase 1 follow-ups carried forward:** (1) renewal reminders + pull-model lapse (Phase 2 M6); (2) unify Orange/PayPal return pages into `billing.checkout.success`; (3) add a `/billing` link to the Filament `/dashboard` chrome (currently only the buyer `account` layout has it); (4) MoMo picker phone-field is format-validated only — real MSISDN validation needs the live MoMo sandbox.

### Phase 2 — Invoicing & tax (~10 days)

**Status 2026-09-11: M5 + M4 shipped & deployed (commits `6727065`, `04d1edf`; prod migrated, TVA rule seeded inactive). Buyer/supplier `/billing` surface in progress.**

- [x] M4: `Invoice` / `InvoiceLine` / `CreditNote` / `CreditNoteLine` models — immutable, each on its own `ChainsIntegrity` hash chain (`Invoice::integrityPayloadColumns()` = number|company_id|payment_id|issued_at|subtotal|tax|total|currency; `status`/`notes` excluded so `void()` doesn't break the chain). `App\Services\Billing\InvoiceIssuer::issueForPayment()` (idempotent by `payment_id`) auto-issues a `paid` invoice on the `PaymentCompleted` outbox event (`IssueInvoiceOnPaymentCompleted` listener, alongside the receipt/activation ones). Numbers `CTH-INV-YYYY-NNNNN` / `CTH-CN-YYYY-NNNNN` (locked-sequence, gap-free). Public print views `/billing/invoices/{invoice}` + `/billing/credit-notes/{creditNote}` with `?format=pdf` (barryvdh/laravel-dompdf, already a dep). `/admin` → **Invoices** resource (list + view + void + issue-credit-note; chain-integrity badge; the §7.6 >threshold two-person gate is a TODO comment) + **Credit Notes** (read-only). `invoices:verify-chain` daily. New `billing.view` permission (`super_admin`+`admin`+`finance_officer`).
- [x] M5: `tax_rules` table + `App\Services\Tax\TaxCalculator` (bcmath, no float drift; XAF 0dp / USD 2dp; `for()` resolves exact-jurisdiction > `*`, segment-specific > all, newest `effective_from` in force). **`/admin` → Tax Rules Filament resource** under a new **`pricing.manage`** permission (`super_admin` + `finance_officer` — not broad `admin`) — name, jurisdiction, rate, applies-to, effective window, active toggle; editing an *active* rule's rate throws (supersede with a new `effective_from` row instead). Cameroon TVA 19.25 % (`jurisdiction: CM`) seeded **`is_active = false`** — stays off until DGI registration is confirmed (§7.3); non-CM → no rule → 0 %. Checkout picker shows a subtotal / TVA / total breakdown only when a rule is active. `PaymentCheckoutController::start()` charges subtotal-only while no rule is active (fully backward compatible); when active, `amount` = total and the breakdown persists to `payment.metadata['tax']` for the invoice. FX capture deferred (follow-up).
- [~] Exporter/admin invoice list (Filament) — **done in M4**. Buyer/supplier `/billing` page (plan + invoices + receipts + payment history) — **in progress**.
- [x] Tests: invoice immutability + `verify-chain` tamper detection; tax pulled from the active rule and shown; changing a rule doesn't touch a past invoice or a snapshotted subscription price; credit note leaves the invoice unchanged; over-crediting throws.

**Phase 2 follow-ups:** FX rate capture on multi-currency charges; partial credit notes currently recorded net of tax (Phase 3); `GET /admin/invoices/create` 500s if typed manually (no create page — staff-only, no UI link).

### Phase 3 — Recurring & lifecycle (~10 days)

**Status 2026-09-11: M6 shipped & deployed (`83a36f5`).**

- [x] M6: **pull-model renewals** (§7.5) — `subscriptions:process-renewals` (`dailyAt('02:30')`): `renews_at−7d` → reminder email (`renewal_reminded_at` stamped, fires once); `renews_at` past → `PastDue` + `grace_until = +7d` (entitlements retained via `inGrace()`); `grace_until` past → `SubscriptionService::lapseToFree()` + email. Free-plan subs skipped entirely. **Trials**: `Plan.trial_days`, `SubscriptionService::startTrial()` — one per company lifetime (any historical `trial_ends_at` row blocks a second), no pre-auth, unpaid-at-expiry lapses via the same job; paying before expiry converts via `activateFromPayment()` (on-time renewal extends from the old `renews_at`, a lapsed/trial/different-plan payment starts fresh from now). `subscriptions:notify-price-changes` (`dailyAt('08:00')`) is a stub pending M9. **Deferred:** card-on-file/auto-charge for USD rails, proration on upgrade/downgrade — documented TODOs, not built.
- [x] Tests: renewal paid → term extends; unpaid → grace → lapse to Free; trial paid → converts, unpaid → lapses to Free.

### Phase 4 — Marketplace commission (~8 days)

**Status 2026-09-11: M7 shipped & deployed (`c3af309`).**

- [x] M7: `App\Services\Commission\CommissionCalculator` (bcmath, capped by amount and/or percent) reading **`/admin` → Commission Rules** (`commission_rules`: segment/plan-tier, domestic/international rate, cap amount + cap %, effective window, active) under the existing `pricing.manage` permission (no new perm). "Protected trade" = a `TradeAssuranceAgreement` exists on the order; `charge()` fires from `TradeAssuranceAgreement::booted()` (idempotent, snapshotted onto the order — a later rule change never rewrites a charged order). Commission preview shown on the buyer's quote-review screen pre-accept. `credit()` is refund-ready (over-credit guarded) but has no refund-flow UI to call it yet. **Not built:** per-enterprise-contract override (no `EnterpriseContract` model exists yet).
- [x] Admin: commission report (`/admin` Filament page — GMV/commission/take-rate by segment) + the rule editor above.
- [x] Tests: rate matches the active rule; a rule edit doesn't change a past order's commission; both caps applied; cancel-before-protection → no commission; `credit()` over-limit throws.

### Phase 5 — Governance & promotions (~6 days)

**Status 2026-09-11: M8 shipped & deployed (`123751a`). M9 and M10 not started.**

- [ ] M9: `plan_prices` with effective-from/until + immutable history; **`/admin` → the Plans Filament resource gains price-version editing** (change a price = new version row, old one retained; `effective_from` scheduling) under `pricing.manage`; "reproducible total from stored inputs" reconstruction test.
- [x] M8: **`/admin` → Coupons** (`Coupon` — code, percent/fixed, applies-to segments/plans, max redemptions total + per-company, validity window, `stacks_with_annual`) + **Credits** (append-only per-company ledger, admin-grantable via a "Grant credit" action, never edited/deleted — corrections are new ledger rows) under `pricing.manage`. `CouponCalculator`/`CreditLedger` are pure services; race-safe redemption (`lockForUpdate`). **Deliberately NOT wired into checkout yet** — `PaymentCheckoutController` untouched; the class docblock documents the integration point (discount the subtotal, then run `TaxCalculator` on the discounted subtotal, never the reverse).
- [ ] M10: wire the `api` plan feature to company API-key issuance quotas.
- [ ] `PRICING_SPEC.md §27` launch checklist — walk every item, tick or file.

**Cross-phase follow-ups:** wire `CouponCalculator` into `PaymentCheckoutController::start()`; wire `CommissionCalculator::credit()` into a refund/dispute UI; an `EnterpriseContract` model for per-contract commission overrides; nothing currently calls `TradeAssuranceAgreement::createDefaultMilestones()` so no live order is commission-protected yet (pre-existing gap, not introduced by M7).

> **Admin-configurable, no deploy (owner direction):** every commercial parameter — gateway credentials (§8), tax rules, commission rules, plan prices + versions, coupons, credits, plan activation/features — is edited in `/admin` under `payments.manage` / `pricing.manage`, never in code or a one-off seeder. Seeders only provide the launch defaults. All edits are written to the hash-chained activity log; changing a rule never mutates a historical invoice, order commission or subscription.

**Totals:** Phase 1 ≈ 12 d · Phase 2 ≈ 10 d · Phase 3 ≈ 10 d · Phase 4 ≈ 8 d · Phase 5 ≈ 6 d → **≈ 46 engineer-days (~9–10 weeks)** after the PayPal account is set up and the MoMo/Orange applications are in, with revenue starting at the end of Phase 1 (domestic on MoMo/Orange first, USD on PayPal in the same phase, MoMo/Orange credentials entered in `/admin` the day they're approved).

---

## 6. Risks

- **MoMo/Orange onboarding timeline** is the critical path and outside engineering control — get the applications in on day one. Engineering can build against the sandbox and go live by swapping credentials in `/admin` (M12), so the code is not blocked.
- **USD via PayPal only at launch** (§7.1) means PayPal's fees and dispute process apply to exporter/international plans; a cleaner USD rail (Stripe, or a merchant-of-record for tax) is a later add, not a launch blocker.
- **Escrow language** — marketing/UI must never say "escrow" or "we hold your funds" for protected trades unless the safeguarding licence exists (`PRICING_SPEC §15`). Commission ≠ escrow.
- **TVA** — the engine assumes Cameroon VAT (19.25 %) applies to domestic subscriptions and is config-switchable; whether CTH is registered and must remit is a DGI/accountant question (§7.3), not a code blocker.
- **Double-charge / webhook replay** — every activation and invoice must be idempotent by `payment_id` / provider reference (the gateways already keep a stable `provider_reference`; enforce it end-to-end).
- **Mobile-money UX** — MoMo/Orange are push-prompt-then-webhook, not synchronous. The checkout success page must say "check your phone and approve" and poll / wait for the webhook; the subscription activates on the webhook, never on the redirect.

---

## 7. Decisions — proposed (confirm or adjust)

### 7.1 USD acceptance — **PayPal Business at launch; Stripe or a merchant-of-record later**
PayPal receives USD from Cameroon with just a business account and no foreign entity, so exporter (`export`) and international-buyer (`buy-international`) plans can go live in Phase 1 alongside the domestic rails. `PayPalGateway` is already coded. Revisit a cleaner USD rail — Stripe via a group entity / Stripe Atlas, or Paddle / Lemon Squeezy as a merchant-of-record that also handles international sales tax — once USD volume justifies the setup. Not a launch blocker.

### 7.2 Launch segments — **all XAF segments + USD via PayPal, from Phase 1**
| Segment | Plans | Rail | Phase 1? |
|---|---|---|---|
| Domestic supplier (`sell`) | Free / Professional 50 k / Enterprise 250 k XAF | MoMo, Orange Money | ✅ |
| Domestic buyer (`buy`) | Buyer Free / Plus 5 k / Business 15 k / Corporate 50 k XAF | MoMo, Orange Money | ✅ |
| Dealer (`deal`) | Free / Pro 10 k / Network 30 k XAF | MoMo, Orange Money | ✅ |
| Verify & comply (`verify-comply`) | Verified Supplier 25 k/yr, Verified Exporter 100 k/yr, Compliance Pro 30 k/mo XAF | MoMo, Orange Money | ✅ (sold like a subscription; verification review stays evidence-based & separate) |
| Exporter (`export`) | Professional $29 / Business $79 / Enterprise $249 /mo | PayPal | ✅ |
| International buyer (`buy-international`) | Free / Professional $39 / Enterprise $199 /mo | PayPal | ✅ |
| Enterprise / institutional | negotiated | bank transfer + admin `assign()` + contract record | manual, always |

### 7.3 VAT — **charge Cameroon TVA at 19.25 % on domestic fees; zero-rate non-Cameroon customers; make it configuration**
Build the tax engine (Phase 2, M5) to apply **19.25 %** to subscription and service fees for customers with a Cameroon billing country, and **0 %** for customers outside Cameroon (export of services). The rate, jurisdiction and effective dates live in `tax_rules` — never hard-coded. **Action for finance/legal:** confirm CTH's TVA registration status and DGI filing obligations; if CTH is not yet required to charge, the engine ships with the Cameroon rule *inactive* and it's flipped on when registration completes — no code change.

### 7.4 Commission — **not at launch; Phase 4; rates managed in `/admin`**
Launch on subscriptions + verification/compliance/data revenue. Marketplace commission (`§15`: 2–5 % plan-tiered, capped) needs protected-trade volume and the pre-commit disclosure UI to be worth the build and the customer friction. Phase 4, once there's GMV to take a rate on. When built, the rate table, caps and effective dates are a **Filament admin resource** (`commission_rules`), editable without deploy; a rule change never re-rates a past order.

### 7.5 Trials & renewals — **pull model, not push (mobile-money reality)**
Card-on-file "charge them automatically at renewal" is clean for Stripe/PayPal but not for MTN MoMo / Orange Money (no reliable stored-mandate/recurring primitive in their standard Collections APIs). So:
- **Trial:** 14 days, **opt-in per plan** (Professional, Buyer Plus, Business Buyer, Dealer Pro, exporter plans — *not* Free, verification products, or enterprise), **one per company lifetime**, **no pre-authorisation**. The company gets full plan entitlements for 14 days; converts when they pay before the trial ends; otherwise lapses to that segment's Free plan. No surprise charge.
- **Renewal:** at `renews_at − 7 days` a re-pay reminder; at `renews_at` the sub goes `past_due` with a 7-day grace (entitlements retained); at end of grace it lapses to Free. For the **USD rails only**, offer optional card-on-file / billing agreement so those customers *can* auto-renew.

### 7.6 Refund / credit-note authority — **`billing.refund`, super_admin + a new `finance_officer` role, two-person over a threshold**
New permission `billing.refund` and a new staff role `finance_officer` (holds `billing.refund` + `pricing.manage` + read on invoices). Every refund / credit note requires a written reason and is written to the hash-chained activity log. Refunds/credits **above 100,000 XAF or $200** require a **second approver** — reuse the two-person pattern (`RequestRefund` / `ApproveRefund`, different approver, fresh 2FA) already used for AI keys and API-key issuance.

### 7.7 Annual pricing — **annual = 10 × monthly (16.7 % off) for every plan with a monthly price**
Apply the `PRICING_SPEC §18` default across the catalogue. Data-only change in `PlanSeeder` / the plan catalogue — no code. Resulting annual list prices:

| Plan | Monthly | **Annual (10×)** |
|---|---|---|
| Supplier Professional | 50,000 XAF | 500,000 XAF |
| Supplier Enterprise | 250,000 XAF | 2,500,000 XAF |
| Buyer Plus | 5,000 XAF | 50,000 XAF |
| Business Buyer | 15,000 XAF | 150,000 XAF |
| Corporate Buyer | 50,000 XAF | 500,000 XAF |
| Dealer Pro | 10,000 XAF | 100,000 XAF |
| Dealer Network | 30,000 XAF | 300,000 XAF |
| Compliance Professional | 30,000 XAF | 300,000 XAF |
| Exporter Professional | $29 | $290 |
| Exporter Business | $79 | $790 |
| Exporter Enterprise | $249 | $2,490 |
| International Buyer Professional | $39 | $390 |
| International Buyer Enterprise | $199 | $1,990 |

Free plans: no annual. Yearly-only plans (Verified Supplier 25 k, Verified Exporter 100 k, Dealer Free): already annual. Enterprise: negotiated. The seeder sets these launch values; from then on every price is edited in `/admin` (Phase 5 M9 — price versions with effective dates, `pricing.manage` permission).

---

## 8. Credentials in the admin panel (owner direction)

MTN MoMo and Orange Money API credentials are entered and rotated in `/admin` (M12), exactly like the AI provider keys today:

- `PaymentSetting` — one row per provider; `subscription_key` / `api_user` / `api_key` / `client_id` / `client_secret` / `merchant_key` all `Crypt::encryptString`-encrypted at rest; `environment` (`sandbox` | `production`); `is_live` toggle.
- `config/payments.php` resolves **DB first, `env()` fallback** — so nothing breaks for local/dev, and production credentials never sit in a file.
- Changing a **live** credential is a two-person + fresh-2FA action: admin A submits the new value (a one-time invite token is shown once), admin B enters that token + a fresh TOTP code to apply it. Same `app/Actions/Ai/RequestAiApiKeyChange` / `ApproveAiApiKeyChange` shape.
- Credentials are never rendered back after save. The Filament page shows only "set / not set" + last-changed + who.
- Gated on a new `payments.manage` permission (super_admin + `finance_officer`).
- The gateway `isConfigured()` checks now read the `PaymentSetting` row, so a provider goes live the moment its credentials are approved — no deploy.
