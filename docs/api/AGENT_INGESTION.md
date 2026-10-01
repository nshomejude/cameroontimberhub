# Agent Ingestion Gateway

The Agent Ingestion Gateway lets AI agents (Hermes first) add **suppliers**
(companies) and their **products** to Cameroon Timber Hub over the JSON API.
Agent data is never shown publicly until a human approves it.

- Base URL: `https://www.cameroontimberhub.com/api/v1/agent`
- OpenAPI: `/docs/api` (tag **Agent ingestion**), JSON at `/docs/api.json`
- Envelopes follow [CONVENTIONS.md](CONVENTIONS.md). Errors look like
  `{"error": {"code", "message", "request_id", "details?"}}`.

## 1. Safety model (summary)

| Guarantee | How |
|---|---|
| Agents never publish anything | Companies are created `status=draft`, `needs_review=true`. Products are always `status=draft`, `needs_review=true`. `status`, `verified_at`, `is_featured`, `plan_id` and similar fields are **prohibited** (422). |
| Hidden until verified | `Company::publiclyVisible()` needs `status=verified` and an active verification badge. Agents can never set either. Staff verify the company (Admin › Companies › Manage › Approve) and, because an unclaimed agent company has no owner to upload documents, issue its badge with **Issue badge (desk-verified)** (permission `verification.review`, written reason required). See §5. |
| Agents cannot touch human data | Agents can only update or add products to companies whose `source` is `agent:*` and that have **no owner users** (unclaimed). Anything else returns 409 `supplier_owned`. |
| Agent keys cannot act as users | Agent keys belong to a service-account user with role `agent`, no company, and no admin or exporter panel access. Outside `/api/v1/agent/*` they get 403 `agent_scope_violation`. |
| Revocation works | Every API request checks `api_key_metas.revoked_at` and returns 401 `token_revoked`. This applies to **all** keys, including company keys. |
| Audit | Every agent write is in the activity log (`log_name=agent-ingestion`). The causer is the agent user and the properties include `token_id` and `request_id`. |
| No SSRF | The server never fetches remote URLs. Images are uploaded as multipart. `image_url` is only stored as provenance. |

## 2. Keys

Keys are created from the CLI on the server. This needs operator access.

```bash
php artisan agent:create-key Hermes                       # agent:ingest,agent:read, 365 days
php artisan agent:create-key Hermes --abilities=agent:read --expires-days=30
php artisan agent:list-keys
php artisan agent:revoke-key 42                           # personal_access_tokens.id
```

`agent:create-key` does the following:

- Creates or reuses the user `agent+hermes@agents.cameroontimberhub.local`
  with a random, unusable password and role `agent`.
- Issues a Sanctum token with the requested abilities and expiry.
- Records `api_key_metas` with `kind=agent`, `company_id=null` and
  `rate_limit_tier=elevated`.
- Prints the token **once**. Store it in the agent's secret store.

The key's **source** is `agent:{slug}`, for example `agent:hermes`. It is
derived from the key and never from the payload. External ids are unique
per source.

| Ability | Grants |
|---|---|
| `agent:read` | `GET /reference`, `GET /suppliers…` |
| `agent:ingest` | All `POST` endpoints |

Send the key as `Authorization: Bearer <token>` and `Accept: application/json`.

There is no admin UI list of agent keys yet. Use `agent:list-keys` and
`agent:revoke-key`.

## 3. Endpoints

All routes are under `/api/v1/agent` with route names `api.v1.agent.*`.

| Method | Path | Ability | Purpose |
|---|---|---|---|
| GET | `/reference` | read | Species, product types, units, currencies, regions and cities, supplier and organisation types, limits |
| POST | `/suppliers` | ingest | Upsert one supplier by `external_id` |
| POST | `/suppliers/batch` | ingest | Up to 50 suppliers, each with up to 50 `products[]`; supports `dry_run` |
| GET | `/suppliers/{id}` | read | Status, visibility and review state |
| GET | `/suppliers?external_id=…` | read | Same, looked up by your external id |
| POST | `/suppliers/{id}/products` | ingest | Upsert one product by `external_id` |
| POST | `/suppliers/{id}/logo` | ingest | Multipart `image` (jpg, png or webp, max 5 MB) |
| POST | `/products/{id}/image` | ingest | Multipart `image` (jpg, png or webp, max 5 MB) |

### 3.1 Reference

```bash
curl -s https://www.cameroontimberhub.com/api/v1/agent/reference \
  -H "Authorization: Bearer $CTH_AGENT_TOKEN" -H "Accept: application/json"
```

```json
{"data": {
  "species": [{"id": 3, "slug": "iroko", "common_name": "Iroko", "scientific_name": "Milicia excelsa", "local_names": ["Abang"], "trade_names": ["African teak"]}],
  "product_types": [{"value": "sawn_timber", "label": "Sawn timber"}],
  "price_units": [{"value": "m3", "label": "m³"}],
  "moq_units": [{"value": "m3", "label": "m³"}],
  "currencies": ["XAF", "USD", "EUR", "GBP", "CNY"],
  "supplier_types": [{"value": "exporter", "label": "Exporter"}],
  "organisation_types": [{"value": "supplier", "label": "Supplier"}],
  "regions": [{"name": "Littoral", "cities": ["Douala", "Edéa"]}],
  "country_code_default": "CM",
  "limits": {"batch_max_suppliers": 50, "batch_max_products_per_supplier": 50, "image_max_kb": 5120, "image_mimes": ["jpg","jpeg","png","webp"]}
}}
```

### 3.2 Upsert a supplier

```bash
curl -s -X POST https://www.cameroontimberhub.com/api/v1/agent/suppliers \
  -H "Authorization: Bearer $CTH_AGENT_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" -H "Idempotency-Key: 6f1c-btl-2026-10-01" \
  -d @supplier.json
```

```json
{
  "external_id": "hermes-btl-douala",
  "legal_name": "Bois Tropicaux du Littoral SARL",
  "trade_name": "BTL Timber",
  "description": "Sawmill and exporter of tropical hardwoods.",
  "supplier_type": "exporter",
  "type": "supplier",
  "email": "sales@btl-timber.cm",
  "phone": "+237690000000",
  "website_url": "https://btl-timber.cm",
  "address_line": "Zone industrielle Bassa",
  "city": "Douala",
  "region": "Littoral",
  "country_code": "CM",
  "latitude": 4.05, "longitude": 9.76,
  "registration_number": "RC/DLA/2020/B/123",
  "species": ["iroko", "sapele", 12],
  "export_markets": ["FR", "CN"],
  "contacts": [{"name": "Jean Mbarga", "role": "Sales manager", "email": "jean@btl-timber.cm", "phone": "+237690000001", "whatsapp": "+237690000001"}],
  "source_url": "https://example.org/directory/btl",
  "image_url": "https://example.org/img/btl-logo.png",
  "evidence": ["Listed in the 2026 MINFOF exporter registry", "Website contact page"],
  "confidence": 0.82,
  "notes": "Phone verified via website footer"
}
```

**Fields**

- Required: `external_id`, plus `legal_name` (or `name`).
- `species` accepts ids or slugs. Unknown values return 422.
- `contacts` are stored **non-public**. Staff decide what is shown.
- `export_markets` are ISO-3166 alpha-2 codes.
- `type` accepts every `organisation_types` value from `/reference`
  **except** `carbon_developer` (422): carbon developers are onboarded by
  humans only.
- Provenance fields: `source_url`, `image_url`, `evidence` (any JSON),
  `confidence` (0–1) and `notes`. These go into `ingestion_meta`.

**Responses**

| HTTP | `result` / `error.code` | Meaning |
|---|---|---|
| 201 | `created` | New hidden draft supplier. |
| 200 | `updated` | Same `external_id` from your source. Profile fields are overwritten, contacts, export markets and species are replaced, and `needs_review` is set again. |
| 200 | `duplicate` | Matches an existing **agent-sourced, unclaimed** supplier, possibly from another agent. Nothing is created. Use `existing_id` to attach products. |
| 409 | `supplier_owned` | Matches a human-owned or claimed company. Nothing is written. `error.details.existing_id` is set. |
| 409 | `supplier_locked` | The supplier (yours, or a duplicate match) has been approved and is in review or verified. Its profile **and logo** can no longer be changed by agents, but products can still be added as drafts. |
| 409 | `supplier_closed` | Staff rejected or archived it. |
| 422 | `validation_failed` | See `error.details`. This includes prohibited fields such as `status`. |

```json
{"result": "created", "data": {"id": 812, "slug": "btl-timber", "source": "agent:hermes", "external_id": "hermes-btl-douala",
  "legal_name": "Bois Tropicaux du Littoral SARL", "trade_name": "BTL Timber", "city": "Douala", "region": "Littoral",
  "country_code": "CM", "status": "draft", "needs_review": true, "publicly_visible": false, "has_logo": false,
  "claimed": false, "products_count": 0, "source_url": "https://example.org/directory/btl",
  "ingested_at": "2026-10-01T09:00:00+00:00", "created_at": "…", "updated_at": "…"}}
```

**Duplicate rules.** These run only when there is no `(source, external_id)`
match, and are checked in this order:

1. Same `registration_number` (case and whitespace insensitive).
2. Same normalised name and city. Names are compared after removing accents,
   punctuation and legal suffixes such as SARL, SA, Ltd and Ets. The name can
   match the existing `legal_name` or `trade_name`.
3. Same email domain or website domain. Free-mail domains such as gmail and
   yahoo are ignored.

### 3.3 Upsert a product

```bash
curl -s -X POST https://www.cameroontimberhub.com/api/v1/agent/suppliers/812/products \
  -H "Authorization: Bearer $CTH_AGENT_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"external_id":"hermes-btl-iroko-50","name":"Iroko Sawn Timber KD 50mm","product_type":"sawn_timber",
       "species_id":"iroko","grade":"FAS","price_amount":650000,"price_currency":"XAF","price_unit":"m3",
       "moq_quantity":20,"moq_unit":"m3","thickness_mm":50,"source_url":"https://btl-timber.cm/products/iroko","confidence":0.7}'
```

- The rule set is the supplier API's own (`StoreSupplierProductRequest`)
  **without `status`**. `species_id` is required unless the product type is
  `charcoal`. It accepts an id or a slug.
- Products are always saved as `draft` with `needs_review=true`.
- Responses: 201 `created`, 200 `updated`, 409 `supplier_owned` /
  `supplier_closed`, 409 `product_locked` (staff already published it), 422.

### 3.4 Batch

```bash
curl -s -X POST https://www.cameroontimberhub.com/api/v1/agent/suppliers/batch \
  -H "Authorization: Bearer $CTH_AGENT_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"dry_run": false, "suppliers": [ { "external_id": "…", "legal_name": "…", "products": [ { "external_id": "…", "name": "…", "product_type": "logs", "species_id": "sapele" } ] } ]}'
```

The batch endpoint always returns HTTP 200. Each item is processed on its
own. A rejected supplier does not affect the others, and its products are
reported as `supplier_rejected`.

```json
{"data": [
  {"index": 0, "external_id": "a", "result": "created", "supplier_id": 812, "code": null, "message": null, "errors": null,
   "products": [{"index": 0, "external_id": "p1", "result": "created", "product_id": 4410, "code": null, "errors": null},
                {"index": 1, "external_id": "p2", "result": "rejected", "product_id": null, "code": "validation_failed", "errors": {"product_type": ["…"]}}]},
  {"index": 1, "external_id": "b", "result": "rejected", "supplier_id": 17, "code": "supplier_owned", "message": "…", "errors": null, "products": []}
],
 "meta": {"dry_run": false, "summary": {"created": 1, "rejected": 1}}}
```

With `dry_run: true`, the batch runs validation and duplicate checks and
reports what *would* happen. Nothing is written.

### 3.5 Images

```bash
curl -s -X POST https://www.cameroontimberhub.com/api/v1/agent/suppliers/812/logo \
  -H "Authorization: Bearer $CTH_AGENT_TOKEN" -H "Accept: application/json" -F image=@logo.png
curl -s -X POST https://www.cameroontimberhub.com/api/v1/agent/products/4410/image \
  -H "Authorization: Bearer $CTH_AGENT_TOKEN" -H "Accept: application/json" -F image=@iroko.jpg
```

Images must be jpg, png or webp and at most 5 MB. The server **never
downloads URLs**. Fetch the image yourself and upload the bytes. You can
send `image_url` in the JSON payload as provenance only.

Uploads are refused once the content is live or under review:

- `POST /suppliers/{id}/logo` returns 409 `supplier_locked` when the
  supplier is no longer `draft` (staff approved the listing).
- `POST /products/{id}/image` returns 409 `product_locked` when staff have
  published the product (`status=active`).
- Both return 404 `not_found` for suppliers or products ingested by a
  **different** agent source. `GET /suppliers/{id}` is scoped the same way.

### 3.6 Status

```bash
curl -s "https://www.cameroontimberhub.com/api/v1/agent/suppliers?external_id=hermes-btl-douala" \
  -H "Authorization: Bearer $CTH_AGENT_TOKEN" -H "Accept: application/json"
```

The response has the same shape as in 3.2. Watch `status`, `needs_review`,
`publicly_visible` and `claimed`.

## 4. Idempotency

Every POST accepts an optional `Idempotency-Key` header of up to 255
characters. Keys are scoped to your token.

- **First request:** runs normally. The JSON response is stored.
- **Retry with the same key and the same payload:** the stored response is
  replayed byte for byte, with the header `Idempotent-Replayed: true`.
  Nothing runs again.
- **Same key, different payload:** 409 `idempotency_conflict`.
- **Same key while the first request is still running:** 409
  `idempotency_in_progress`. Retry later.
- **5xx responses:** these are not stored, so a retry runs again.
- **Retention:** records are kept for 7 days. They are pruned daily by
  `agent:prune-idempotency-keys`.

Upserts by `external_id` are already idempotent. The header also protects
batches and uploads against network retries.

## 5. Moderation lifecycle

```
agent POST ──► draft + needs_review (hidden)
                 │  Admin › Companies › filter "Agent submissions: awaiting review"
                 ├─ "Approve listing" ─► needs_review=false, enters the normal
                 │                       verification flow (draft → pending + VerificationRequest)
                 │      └─ staff "Approve" ─► verified, then "Issue badge (desk-verified)"
                 │             └─ "Publish products" ─► agent draft products → active
                 │                (refused while the company is not verified)
                 ├─ "Reject submission" ─► company archived, its agent products archived
                 └─ "Attach owner (claim)" ─► a real user becomes owner; agents locked out
```

A company appears publicly only when `Company::publiclyVisible()` is true.
That requires verified status, a logo, a description, a region, species,
contacts and an active badge.

**Staff runbook: taking an unclaimed agent company live** (Admin ›
Companies, filter "Agent submissions: awaiting review", row menu **Manage**):

1. Check the profile (Edit). Make sure it has a logo, description, region,
   at least one species and one contact — agents cannot change it after
   step 2.
2. **Approve listing** (`agent-submissions.moderate` or `companies.manage`).
   Clears `needs_review` and moves the company `draft → pending`. From now on
   agents get `supplier_locked` for the profile and logo.
3. Verify the company out-of-band (registry lookup, phone call, site visit).
4. **Approve** (`companies.manage`): `pending → verified`.
5. **Issue badge (desk-verified)** (`verification.review`). Pick the badge
   (default *Verified Company*) and write how the company was verified. The
   normal document-backed badge path is impossible here because nobody can
   upload documents for an unclaimed company. The badge is stored with
   `issued_manually=true` and `manual_reason`, is logged as
   `badge_issued_manually` (log `compliance`, properties
   `source=desk_verified`, `reason`) and expires after 12 months.
6. **Publish products**: every agent draft product → `active` (refused
   while the company is not verified). Agents then get `product_locked` for
   those products.
7. Confirm with `GET /api/v1/agent/suppliers/{id}` (or the Badge column)
   that `publicly_visible` is now `true`; the profile page lists any
   remaining gap.

**Claiming.** A real supplier who finds their agent-created profile contacts
support. Staff run **Attach owner (claim)** with the supplier's account
email. This adds an `owner` row in `company_user` and sets `created_by`.
From then on, agents get `supplier_owned` for that company.

## 6. Rate limits

| Limiter | Limit |
|---|---|
| `api-agent` | 600 requests per minute per token |
| `api-key` | Applies to all of v1. Agent keys use tier `elevated`. |

When you exceed a limit you get 429 `rate_limited` with `Retry-After`.

## 7. Error codes

| HTTP | code | When |
|---|---|---|
| 401 | `unauthenticated` | Missing, invalid or expired token |
| 401 | `token_expired` | Expired token on an agent route |
| 401 | `token_revoked` | Key was revoked (any API route, any key) |
| 403 | `agent_token_required` | Not an agent key (for example a company owner key) |
| 403 | `missing_ability` | Key lacks `agent:ingest` / `agent:read` |
| 403 | `agent_scope_violation` | Agent key used outside `/api/v1/agent` |
| 404 | `not_found` | Unknown or non-agent supplier or product |
| 409 | `supplier_owned` / `supplier_locked` / `supplier_closed` / `product_locked` | See 3.2 and 3.3 |
| 409 | `idempotency_conflict` / `idempotency_in_progress` | See 4 |
| 422 | `validation_failed` | `error.details` has per-field messages |
| 429 | `rate_limited` | Slow down |

## 8. Hermes integration guide

Recommended loop:

1. **`GET /reference`** once per run. Cache species slugs, product types,
   units and regions.
2. **Map** each scraped supplier into the schema above:
   - Give it a **stable `external_id`**, for example a hash of the
     canonical source URL plus the company name.
   - Map species names to slugs. Map city and region to the reference
     values.
   - Fill `evidence`, `confidence` and `source_url`.
3. **`POST /suppliers/batch`** in chunks of 50 or fewer, with nested
   `products[]`, each with its own stable `external_id`. Send an
   `Idempotency-Key` per chunk, for example `run-<date>-chunk-<n>`. Try
   `dry_run: true` first when testing a new mapper.
4. **Handle results:**
   - `created` / `updated`: remember `supplier_id`.
   - `duplicate`: use `supplier_id` (the existing agent supplier) for
     products and uploads.
   - `rejected` with `supplier_owned`: stop. The company is managed by a
     human, so do not retry with a new `external_id`.
   - `validation_failed`: fix the mapping.
5. **Upload images.** Download them yourself, then call
   `POST /suppliers/{id}/logo` and `POST /products/{id}/image`.
6. **Poll** `GET /suppliers?external_id=…` daily to track `needs_review`,
   `status` and `publicly_visible`. Stop updating a supplier once it
   returns `supplier_locked`.

**OpenAPI-friendly example (minimal batch body):**

```yaml
requestBody:
  content:
    application/json:
      schema:
        type: object
        required: [suppliers]
        properties:
          dry_run: {type: boolean, default: false}
          suppliers:
            type: array
            maxItems: 50
            items:
              type: object
              required: [external_id, legal_name]
              properties:
                external_id: {type: string, maxLength: 191}
                legal_name: {type: string, maxLength: 255}
                trade_name: {type: string}
                supplier_type: {type: string, enum: [manufacturer, processor, exporter, trader, service_provider, logistics_provider]}
                city: {type: string}
                region: {type: string}
                species: {type: array, items: {oneOf: [{type: integer}, {type: string}]}}
                contacts: {type: array, maxItems: 10, items: {type: object, required: [name], properties: {name: {type: string}, role: {type: string}, email: {type: string}, phone: {type: string}, whatsapp: {type: string}}}}
                source_url: {type: string, format: uri}
                confidence: {type: number, minimum: 0, maximum: 1}
                products:
                  type: array
                  maxItems: 50
                  items:
                    type: object
                    required: [external_id, name, product_type]
                    properties:
                      external_id: {type: string}
                      name: {type: string, maxLength: 200}
                      product_type: {type: string}
                      species_id: {oneOf: [{type: integer}, {type: string}]}
                      price_amount: {type: number}
                      price_currency: {type: string, maxLength: 3}
                      price_unit: {type: string, enum: [m3, m2, pcs, ton]}
```
