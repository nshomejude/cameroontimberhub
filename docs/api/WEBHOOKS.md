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

On creation CTH generates a plaintext secret (`whsec_` + 40 random chars) and
**displays it exactly once**. It is stored **encrypted at rest** and is used
directly as your HMAC signing key (Stripe/GitHub `whsec_...` model). If you
lose it, rotate the subscription; CTH cannot show it again.

---

## Event envelope

Every delivery body is a standard envelope (not the raw domain payload):

```json
{
  "id": "evt_01J9Z8XABCDEF0123456789AB",
  "type": "order.awarded",
  "created": 1736500000,
  "data": { "order_id": 5, "country_code": "DE" }
}
```

- `id` — stable per delivery. All retry attempts of the same delivery carry
  the **same** `id`; use it as your idempotency key.
- `type` — the event type (also in the `X-CTH-Event` header).
- `created` — unix timestamp when this attempt was signed (equals the `t` in
  the signature header for this attempt).
- `data` — the domain event payload (the flat field set documented per event
  in the catalog below).

Headers on every delivery:

| Header | Value |
|---|---|
| `X-CTH-Signature` | `t=<unix>,v1=<hmac-sha256 hex>` (see below) |
| `X-CTH-Event` | the event `type` |
| `X-CTH-Delivery` | the envelope `id` (`evt_...`) |
| `X-CTH-Timestamp` | the signing unix timestamp (same as `t`) |

---

## Signature verification

CTH signs the **exact bytes** it transmits. The body is JSON-encoded once with
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` and that same string is both
signed and sent as the raw request body — so verify against the raw body you
received, byte for byte, before any re-parsing.

**Scheme** (Stripe-style, replay-protected):

- Header: `X-CTH-Signature: t=<unix timestamp>,v1=<hex>`
- `<hex>` = `HMAC_SHA256(key = <your plaintext secret>, message = "<t>" + "." + <raw body>)`
- The key is your plaintext `whsec_...` secret **directly** — no hashing.

To verify:

1. Read `t` and `v1` from the `X-CTH-Signature` header.
2. Recompute `HMAC_SHA256(secret, t + "." + rawBody)`.
3. Constant-time compare against `v1`.
4. Reject if `t` is more than **5 minutes** from your current time (replay
   protection).

Node.js example:

```js
const crypto = require('crypto');

function verifyCthWebhook(rawBody, signatureHeader, secret, toleranceSeconds = 300) {
  const parts = Object.fromEntries(
    signatureHeader.split(',').map((kv) => kv.split('=')),
  );
  const t = Number(parts.t);
  const v1 = parts.v1;

  if (!Number.isFinite(t) || Math.abs(Date.now() / 1000 - t) > toleranceSeconds) {
    throw new Error('Timestamp outside tolerance — possible replay');
  }

  const expected = crypto
    .createHmac('sha256', secret)
    .update(`${t}.${rawBody}`)
    .digest('hex');

  const ok =
    v1.length === expected.length &&
    crypto.timingSafeEqual(Buffer.from(v1), Buffer.from(expected));

  if (!ok) throw new Error('Bad signature');

  return JSON.parse(rawBody); // the envelope: { id, type, created, data }
}
```

PHP example:

```php
[$t, $v1] = [null, null];
foreach (explode(',', $signatureHeader) as $kv) {
    [$k, $val] = explode('=', $kv, 2);
    if ($k === 't') $t = (int) $val;
    if ($k === 'v1') $v1 = $val;
}

abort_if($t === null || abs(time() - $t) > 300, 400, 'Stale webhook');

$expected = hash_hmac('sha256', $t.'.'.$rawBody, $yourPlaintextSecret);
abort_unless(hash_equals($expected, (string) $v1), 400, 'Bad signature');
```

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

14 event types. The "Payload fields" below are what each event's `payload()`
method emits (flat: IDs + a few scalars — re-fetch detail over `/api/v1`);
they arrive nested under the envelope's `data` key.
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
