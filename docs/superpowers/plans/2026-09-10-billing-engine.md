# Billing Engine — Proposal & Phased Plan

> Companion to `docs/PRICING_SPEC.md` (the commercial source of truth) and `docs/GAP_PLAN.md` item 0.9b.
> **Date:** 2026-09-10 · **Status:** proposal, awaiting owner decisions (§7)

**Bottom line:** the billing engine is **~65% built**. The payment gateways, plan catalogue, subscription model, entitlement engine and the payment→outbox event chain already exist. What's missing is (a) two merchant-account applications, and (b) roughly **9–10 engineer-weeks** of work — phased so that *domestic subscriptions can go live ~2 weeks after the merchant accounts clear*.

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

**Recommendation:** launch domestic first on **MTN MoMo + Orange Money**; bring USD (Stripe or a merchant-of-record) online for exporters/international buyers once the entity question (§7.1) is resolved. PayPal as a fast USD fallback in the meantime.

---

## 5. Phased plan

Effort is engineer-days for someone who knows this codebase. **Phase 0 is a business action and can start today.**

### Phase 0 — Merchant accounts (business, 0 eng, blocks Phase 1 go-live)
- [ ] Apply for MTN MoMo Collections (Cameroon) — KYB, settlement bank account
- [ ] Apply for Orange Money Web Payment (Cameroon)
- [ ] Decide the USD entity/MoR question (§7.1)
- [ ] Confirm VAT position (§7.3) with finance/legal
- [ ] Choose which segments launch first (§7.2)

### Phase 1 — "Plans you can actually buy" (~10 days) → **domestic subscriptions go live**
- [ ] M1: `ActivateSubscriptionOnPaymentCompleted` listener — a completed plan `Payment` → `SubscriptionService::activateFromPayment($payment)` (new method: sets term from `plan.billing_period`, `renews_at`, snapshots price, links `payment_id`). Idempotent by `payment_id`.
- [ ] M2: rewire MTN / Orange / PayPal `handleWebhook()` to dispatch `RecordPaymentCompletionCommand` (mirror `StripeGateway`).
- [ ] M3: additive `subscriptions` migration — `billing_period`, `renews_at`, `trial_ends_at`, `grace_until`, `payment_id`, `provider_reference`, `price_amount`, `price_currency`.
- [ ] M11: `/pricing` → checkout flow for the launch segments. Plan card → payment-method picker (MoMo / Orange Money / card if USD) → `POST /payments/checkout/{plan}` → gateway. Success page + a `Receipt` (reuse `ChainsIntegrity`).
- [ ] Renewal-reminder email 7 days before `renews_at` with a re-pay link (no card-on-file yet — manual re-pay in Phase 1).
- [ ] Tests: full loop per gateway in sandbox; idempotent double-webhook; unconfigured-gateway refusal; entitlement flips on activation.
- [ ] Deploy domestic (MoMo + OM). USD when Phase 0's entity clears.

### Phase 2 — Invoicing & tax (~10 days)
- [ ] M4: `Invoice` / `InvoiceLine` / `CreditNote` models; auto-invoice on charge (immutable — guard `updating` like `ChainsIntegrity`); invoice PDF; credit-note flow.
- [ ] M5: `tax_rules` table + `TaxCalculator`; Cameroon VAT 19.25 % as a configured default; checkout breakdown (subtotal/discount/tax/fees/total); FX capture.
- [ ] Exporter/admin invoice list (Filament); buyer/supplier "Billing" page (invoices + receipts + payment method).
- [ ] Tests: invoice immutability; tax applied and shown; credit note leaves the invoice unchanged.

### Phase 3 — Recurring & lifecycle (~10 days)
- [ ] M6: token/card on file (Stripe `SetupIntent`); scheduled `subscriptions:charge-renewals` job; dunning state machine (`active → past_due → suspended → restored`) + email sequence; proration; trials; 30-day price-change notice job; grace-period entitlement behaviour (`Company::hasFeature()` respects `suspended`).
- [ ] Tests: renewal charge succeeds → term extends; fails → grace → suspend → restore; upgrade prorates; trial converts.

### Phase 4 — Marketplace commission (~8 days)
- [ ] M7: `CommissionCalculator` (deterministic, plan-tiered rate from §15, capped, versioned `commission_rules`); commission line on protected-trade orders shown pre-commit; recorded on order + invoice; not charged on pre-acceptance cancel; refund treatment on the credit note.
- [ ] Admin: commission report; per-contract override (audited).
- [ ] Tests: rate matches plan tier; cap applied; cancel-before-acceptance → no commission; refund → commission credit.

### Phase 5 — Governance & promotions (~5 days)
- [ ] M9: `plan_prices` with effective dates + immutable history; "reproducible total from stored inputs" reconstruction test; pricing-admin permission (`pricing.manage`), least-privilege.
- [ ] M8: `Coupon` / `Credit` / referral credit; stacking off by default; coupons never reduce tax.
- [ ] M10: wire the `api` plan feature to company API-key issuance quotas.
- [ ] `PRICING_SPEC.md §27` launch checklist — walk every item, tick or file.

**Totals:** Phase 1 ≈ 10 d · Phase 2 ≈ 10 d · Phase 3 ≈ 10 d · Phase 4 ≈ 8 d · Phase 5 ≈ 5 d → **≈ 43 engineer-days (~9 weeks)** after merchant accounts land, with revenue starting at the end of Phase 1.

---

## 6. Risks

- **MoMo/OM onboarding timeline** is the critical path and outside engineering control — start Phase 0 immediately.
- **USD without a Stripe entity** — if a merchant-of-record (Paddle) is chosen, it changes the checkout integration for USD plans (their hosted checkout, their tax handling) — decide before Phase 1 touches USD.
- **Escrow language** — marketing/UI must never say "escrow" or "we hold your funds" for protected trades unless the safeguarding licence exists (`PRICING_SPEC §15`). Commission ≠ escrow.
- **VAT** — if CTH must charge and remit Cameroon VAT on subscriptions, that's a Phase 2 dependency and a finance/registration task.
- **Double-charge / webhook replay** — every activation and invoice must be idempotent by `payment_id` / provider reference (the gateways already keep a stable `provider_reference`; enforce it end-to-end).

---

## 7. Decisions needed from the owner (before Phase 1)

1. **USD acceptance:** Stripe via a supported-country entity, Stripe Atlas, or a merchant-of-record (Paddle/Lemon Squeezy)? Or launch USD on PayPal only and add the rest later?
2. **Launch segments:** which plans are buyable at launch? *Recommendation:* domestic supplier (`sell`) + domestic buyer (`buy`) on MoMo/OM; exporters + international buyers once USD is settled; verification/compliance products next; enterprise stays manual.
3. **VAT:** does CTH charge Cameroon VAT (19.25 %) on subscriptions today? Registered? (finance/legal)
4. **Commission at launch or later?** *Recommendation:* subscriptions-only first; commission is Phase 4 once protected-trade volume justifies it.
5. **Trials:** offer a 14-day trial? On which plans?
6. **Refund / credit-note authority:** which role can issue one (`billing.refund` permission)?
7. **Annual pricing:** the seeded plans are mostly monthly — confirm the annual price for each (spec default: 10 months' price for 12 months) so the catalogue is complete per `§27`.
