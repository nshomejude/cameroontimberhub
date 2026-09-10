# API documentation (`/api/v1`)

**See also:** [`CONVENTIONS.md`](CONVENTIONS.md) (versioning, auth, error
shapes, rate limits), [`WEBHOOKS.md`](WEBHOOKS.md) (webhook event catalog),
[`../architecture/README.md`](../architecture/README.md) (bounded contexts,
CQRS, event backbone), [`../architecture/GAPS.md`](../architecture/GAPS.md).


The OpenAPI 3.1 spec for the buyer JSON API (`routes/api.php`, `v1` group) is
generated automatically from code — no hand-written YAML/JSON to maintain.

## Tooling

We use [`dedoc/scramble`](https://scramble.dedoc.co/) rather than
`darkaonline/l5-swagger`. Scramble infers the spec from route signatures,
`FormRequest` validation rules and controller return types, which is what
every `App\Http\Controllers\Api\V1\*` action already has — no `@OA\*`
annotations to write or keep in sync. The reasoning is recorded as a comment
at the top of `config/scramble.php`.

## Where it's served

- `GET /docs/api` — interactive docs UI (Stoplight Elements). Public,
  read-only, no authentication required — it's documentation, not the API
  itself.
- `GET /docs/api.json` — the raw OpenAPI 3.1 document.

Both are scoped to `api/v1` only (`config/scramble.php` → `api_path`).

## Regenerating / inspecting the spec locally

Nothing to "build" — the spec is generated on request. To warm/inspect it
from the CLI instead of a browser:

```bash
php artisan scramble:export        # writes the document to storage (see export_path in config)
php artisan scramble:cache         # warm the cached document (config('scramble.cache'))
php artisan scramble:clear         # clear the cached document
```

## Keeping coverage from silently dropping

`tests/Feature/Api/OpenApiDocsTest.php` asserts:

1. `GET /docs/api` and `GET /docs/api.json` both return 200, and the JSON
   document is a well-formed OpenAPI 3.1 object.
2. The number of documented paths is `>=` the number of distinct route URIs
   actually registered under `api/v1` in `routes/api.php` — a route added to
   the app without Scramble being able to see it fails this test.
3. A handful of known routes (`products.index`, `rfqs.store`,
   `quotes.accept`) are present in the generated `paths`.

If a new `/api/v1` controller action stops appearing in the spec, first
check that it's type-hinted with a concrete return type (a Resource, a
`JsonResponse`, a `Response`) and that its request uses a `FormRequest` with
real validation rules — that's the pair of signals Scramble reads. Avoid
`mixed`/untyped returns on API actions going forward for this reason, not
just for the spec's sake.

## Adding documentation-only detail

If a route later needs an accurate but hard-to-infer detail (e.g. a
polymorphic response), prefer Scramble's `@response`/`@queryParam`-free PHP
attributes / doc-comment support before reaching for a full annotation
system — see the [Scramble docs](https://scramble.dedoc.co/) for the current
list. Any such addition must be documentation-only and must not change a
controller's actual behaviour.
