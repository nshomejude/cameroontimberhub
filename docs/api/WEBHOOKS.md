# CTH Webhooks — event catalog & consumer guide

Webhooks are the outbound half of "the API as a product": CTH posts a JSON
payload to a URL you control whenever a domain event you subscribed to fires.
They are driven by the same transactional outbox that powers internal event
listeners (see [`../architecture/README.md`](../architecture/README.md) §3), so
a webhook fires **if and only if** the underlying state change committed.

---

## Subscribing

A company subscribes from the **exporter panel → Webhook Subscriptions**
(`App\Filament\Exporter\Resources\WebhookSubscriptions`). A subscription has:

- **URL** — your HTTPS endpoint (max 2048 chars).
- **Active** toggle — inactive subscriptions receive nothing.
- **Subscribed events** — a fixed checklist (not free text); the options are
  the event types CTH's outbox relay can actually deliver.

### Secret — shown once

On creation CTH generates a 40-character plaintext secret and **displays it
exactly once**. Only a SHA-256 hash of it (`secret_hash`) is stored — the same
model as an API key's token. If you lose it, rotate the subscription; CTH
cannot show it again.

---

## Signature verification

> ⚠️ **Subject to change before GA.** A hardening pass is in progress on the
> webhook signature scheme (a signed envelope with event id + timestamp,
> signing over exact transmitted bytes, an encrypted rather than hashed
> secret, and replay protection via a timestamp tolerance). The scheme below
> is what the code does **today**; the specifics in this section will be
> finalised by that work. Build your consumer to verify a signature, but
> expect the exact construction to firm up.

<!-- TODO(webhook-hardening agent): finalise this section against the shipped
     envelope + exact-bytes + encrypted-secret + replay-protection scheme.
     Keep the "what a consumer should do" shape; update the construction. -->

**Current scheme** (`App\Services\Webhooks\WebhookDeliveryService::sign()`):

- Header: `X-CTH-Signature: sha256=<hex>`
- `<hex>` = `hash_hmac('sha256', json_encode($payload), $key)`
- `$key` is **the SHA-256 hex hash of your plaintext secret** — i.e. CTH signs
  with the same value it stored, and you derive it by hashing your copy of the
  secret. CTH never persists a reversible secret.

To verify (pseudocode):

```
key        = sha256_hex(your_plaintext_secret)
expected   = hmac_sha256_hex(key, raw_request_body)
valid      = hash_equals(expected, header.removePrefix("sha256="))
```

Caveats with the current scheme, all addressed by the hardening pass:

- The signed bytes are PHP's `json_encode()` of the payload array, not
  guaranteed identical to the received body under all conditions — prefer
  verifying against the exact received bytes and treat a mismatch as
  advisory until GA.
- No timestamp / event-id in the signed material yet, so no replay protection.

---

## Delivery semantics

`App\Jobs\DeliverWebhookJob` — **3 attempts** on a fixed schedule:

| Attempt | When |
|---|---|
| 1 | immediately |
| 2 | +1 minute (if attempt 1 was not `2xx`) |
| 3 | +10 minutes (if attempt 2 was not `2xx`) |

- Success = HTTP `2xx`. The `WebhookDelivery` row is stamped `delivered_at`.
- After the 3rd failed attempt the row is stamped `failed_permanently_at` and
  never retried automatically.
- HTTP timeout per attempt is 5 seconds; a connection error counts as a failed
  attempt (`response_code` null).
- Every attempt cycle is one `webhook_deliveries` row, visible in the **admin
  delivery log** with a manual **retry** action. Terminal invariant:
  `delivered_at` XOR `failed_permanently_at`, never both.

Your endpoint should be **idempotent** — a delivered event can still be
retried if your `2xx` was slow, and the relay itself retries a row up to 5
times if webhook dispatch throws.

---

## Event catalog

14 event types. Payload fields are exactly what each event's `payload()`
method emits (flat: IDs + a few scalars — re-fetch detail over `/api/v1`).
"Recipient" is the company whose active subscription receives the delivery,
as resolved by `RelayOutboxEventsJob::deliverWebhooksFor()`.

> **Catalog vs. subscribable list mismatch (known gap):** all 14 below are
> deliverable by the relay, but the exporter-panel subscription form
> (`WebhookSubscriptionForm::EVENT_TYPES`) currently only lists the first
> three (`order.awarded`, `checkpoint.recorded`, `compliance_case.opened`).
> The other 11 cannot be subscribed to via the UI yet. Tracked in
> [`../architecture/GAPS.md`](../architecture/GAPS.md).

### Trade

| `event_type` | Fires when | Payload fields | Recipient |
|---|---|---|---|
| `order.awarded` | An Order is created from an accepted Quote (`OrderService::createFromQuote()`). | `order_id`, `country_code` (nullable) | Buyer's company (`orders.company_id`) |
| `order.shipped` | Order status moves to `shipped` (`OrderService::ship()`, via `RecordOrderShipmentCommand`). | `order_id` | Buyer's company (`orders.company_id`) |
| `order.delivered` | Order status moves to `delivered` (`OrderService::deliver()`, via `RecordOrderDeliveryCommand`). | `order_id` | Buyer's company (`orders.company_id`) |
| `quote.declined` | Buyer declines a Quote (`QuoteService::decline()`, via `DeclineQuoteCommand`). | `quote_id`, `company_id` (nullable), `reason` (nullable) | Supplier company that submitted the quote (`company_id` on payload) |
| `quote.withdrawn` | Supplier withdraws its Quote (`QuoteService::withdraw()`, via `WithdrawQuoteCommand`). | `quote_id`, `company_id` (nullable), `reason` (nullable) | Supplier company that submitted the quote (`company_id` on payload) |

### Logistics & Traceability

| `event_type` | Fires when | Payload fields | Recipient |
|---|---|---|---|
| `checkpoint.recorded` | A `CheckpointUpdate` is recorded against a Shipment. | `checkpoint_update_id`, `shipment_id`, `status`, `location` (nullable) | Buyer's company via `Shipment → Order.company_id` |
| `lot_transformation.recorded` | A mass-balance transformation is recorded (`LotTransformation::recordFor()`, via `RecordLotTransformationCommand`). | `lot_transformation_id`, `processor_company_id`, `transformation_type` | Company that owns the **first input `TimberLot`** — deliberately not the processor |

### Compliance & Trust

| `event_type` | Fires when | Payload fields | Recipient |
|---|---|---|---|
| `compliance_case.opened` | A `ComplianceCase` is opened (today via `OpenComplianceCaseOnOrderAwarded`). | `compliance_case_id`, `owner_type`, `owner_id`, `country_code` | Resolved from the polymorphic owner: `Company` → itself; `Order`/`Shipment`/`TimberLot` → their owning company |
| `dispute.opened` | A `Dispute` is opened against an Order (`OpenDisputeCommand`). | `dispute_id`, `order_id`, `raised_by_company_id` (nullable), `respondent_company_id` (nullable) | **Both** companies on the payload (up to two independent deliveries) |
| `inspection.finalised` | An Inspection report is finalised (`Inspection::finalise()`, via `FinaliseInspectionCommand`). | `inspection_id`, `timber_lot_id` (nullable), `order_id` (nullable), `result` (nullable) | Owner via whichever of `timber_lot_id` / `order_id` is set → owning company |

### Identity & Access

| `event_type` | Fires when | Payload fields | Recipient |
|---|---|---|---|
| `company.verified` | A company reaches terminal `Verified` status (`VerificationService::approve()`, via `ApproveVerificationCommand`). | `company_id`, `verification_request_id` | The verified company (`company_id` on payload) |

### Catalog

| `event_type` | Fires when | Payload fields | Recipient |
|---|---|---|---|
| `product.published` | A Product listing goes live / becomes `Active` (`ProductObserver::maybeCreateLot()` / `PublishProductCommand`). | `product_id`, `company_id` | The listing's company (`company_id` on payload) |

### Commerce & Billing

| `event_type` | Fires when | Payload fields | Recipient |
|---|---|---|---|
| `subscription.activated` | A Subscription becomes the company's active plan (`SubscriptionService::assign()`, via `AssignSubscriptionCommand`). | `subscription_id`, `company_id`, `plan_id` | The company (`company_id` on payload) |
| `payment.completed` | A Payment is confirmed captured (`Payment::markCompleted()`, from any of the 4 gateway webhook handlers, via `RecordPaymentCompletionCommand`). | `payment_id`, `company_id`, `provider`, `amount`, `currency` | The company (`company_id` on payload) |
