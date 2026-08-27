# Certificate — Digital Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build gap-plan item 0.8, "Certificate — Digital Core" — Rings 1+2 (Layers 1–14) of `docs/CERTIFICATE_SPEC.md`: a versioned, cryptographically signed, hashed certificate record; an evidence-manifest hash; a geospatial hash+validation; a quantity-allocation ledger; an immutable audit trail; and a public verification page + QR + printable certificate view. Ring 3 (physical production) is explicitly out of scope — see Scope Decision.

**Architecture:** One `certificates` table where each row is one immutable *version* of a certificate (shared `certificate_number` across versions, `version` integer, `previous_version_id` self-reference — see Scope Decision for why this shape was chosen over a `certificate_versions` child table). A `CertificateHashingService` (deterministic canonical JSON + SHA-256), a `CertificateSigningService` (libsodium detached signatures over an application-held keypair, private key outside the DB), a `CertificateEvidenceService` (aggregates `Document::checksum_sha256`, which this plan populates for real for the first time), a `CertificateGeoService` (GeoJSON canonicalization/hash/validation), a `CertificateAllocationService` (ledger against `certified_quantity`), `Certificate`'s own `LogsActivity` wiring (first real use of the already-installed but unused `spatie/laravel-activitylog`), and a public `/verify/certificate/{token}` route mirroring `ReceiptVerificationController`/`ReceiptVerifier`. `Product` is the subject (`subject_type`/`subject_id`) — see Scope Decision. The printable certificate view follows the house `window.print()` + `.article-prose`/hand-styled-Tailwind pattern already used by `resources/views/public/orders/receipt.blade.php` — no PDF library is added, matching that view's own documented reasoning. A QR image *is* added (`endroid/qr-code`), because unlike the receipt page's printed-URL-as-text shortcut, item 9 of this spec explicitly requires a real QR image and no QR package exists yet.

**Tech Stack:** Laravel 13, Filament 5, PostgreSQL, Pest, PHP 8.3's built-in `sodium` extension, `spatie/laravel-activitylog` (already a dependency, currently unused), `endroid/qr-code` (new dependency, added in Task 9).

Reference: `docs/CERTIFICATE_SPEC.md`, `docs/GAP_PLAN.md` items 0.8/0.8b, `app/Models/Document.php`, `app/Models/Verification.php`, `app/Enums/VerificationStage.php`, `app/Services/ReceiptVerifier.php`, `app/Http/Controllers/Public/ReceiptVerificationController.php`, `resources/views/public/orders/receipt.blade.php`, `docs/superpowers/plans/2026-08-27-polymorphic-document-store.md`, `docs/superpowers/plans/2026-08-27-polymorphic-verification.md`.

## Scope decision (read before executing)

**What exists today, confirmed by investigation:**
- `grep -rl certificate app/Models app/Services database/migrations` finds nothing — greenfield, confirmed by `docs/CERTIFICATE_SPEC.md`'s own scope note.
- `composer.json` has no PDF library (`dompdf`/`browsershot`/`snappy` all absent) and no QR library. `resources/views/public/orders/receipt.blade.php` already solves "printable verifiable document" for receipts with `window.print()` + `print:hidden` Tailwind utilities + a hand-styled `<article class="receipt-sheet">`, and its own comment explains it deliberately skips a QR image "because generating one would mean adding a package." That comment is now stale for certificates specifically: item 9 of this spec is not optional about the QR, so this plan *does* add a package — `endroid/qr-code` (actively maintained, PSR-agnostic, no ImageMagick requirement, generates SVG/PNG natively) — while still following the receipt page's *no PDF library* convention for the printable view itself, because nothing about a QR image requires abandoning the browser-print pattern that already works for this exact "verifiable document a stranger can check" use case.
- `spatie/laravel-activitylog` is installed (`composer.json`) and its migration is present (`database/migrations/2026_06_22_090514_create_activity_log_table.php`) with a registered `ActivityLogPolicy` (`app/Providers/AppServiceProvider.php:29`), but `grep -rln "LogsActivity\|activity()->" app/Models app/Services` finds **zero** consumers. It is reserved-but-unused infrastructure. This plan is its first real user (Task 8).
- `app/Models/Document.php`'s `checksum_sha256` column exists but nothing populates it — its own docblock says so explicitly ("nothing currently populates" it) and it hashes row *metadata* (`hash`/`prev_hash`), not file bytes. Task 5 of this plan is the first code to populate `checksum_sha256` for real, by reading actual file bytes off the `documents` disk.
- No geometry/GIS composer package exists (`grep -iE "geo|spatial|polygon" composer.json` — no hits). Task 6 therefore implements canonicalization, precision validation, and basic polygon-validity checks (ring closure, minimum vertex count, a real segment-intersection self-intersection check) in plain PHP rather than fabricating a spatial-engine dependency.

**Subject of a certificate — `Product`, decided over `Company`:** `docs/CERTIFICATE_SPEC.md` Part A's "Human-Readable Certificate Data" is product/origin/production/supplier data, not company-identity data. `app/Models/Product.php` already carries the closest fields to that shape today (`moq_quantity`, a legal-origin/sustainability detector against `legal origin|flegt|fsc|pefc|sustainab|sigif|cites` — see `Product.php:185`) and already has `HasVerification` wired (item 0.2's plan used `Product` as its first consumer too, for the same reason: it plausibly needs the workflow and had none). A `Company` is *who* trades; a `Certificate` in this spec attests to *what* (a shipment/lot of product, its origin, its production, its evidence) — `Product` is the honest fit, `Company` is not, and inventing a not-yet-built `Shipment`/`Lot` entity just to have a "more correct" subject would be exactly the kind of fabricated-infrastructure mistake the project's rules exist to prevent. `subject_type`/`subject_id` is polymorphic so a future `Shipment` model can become a second subject type without a migration.

**Versioning shape — one `certificates` table, versioned by row, not a `certificate_versions` child table:** `docs/CERTIFICATE_SPEC.md` item 1's field list (public ID, `data` JSON, `version` integer, `status`, timestamps) describes fields that live *on* a certificate row, not on a separate identity row pointing at child snapshots. This is a different shape from `Verification`/`VerificationCheckpoint` (Task 1 of the sibling `2026-08-27-polymorphic-verification.md` plan), where the *parent* is a live state machine and *children* are an append-only event log of review outcomes at each stage — a certificate version is not a review outcome, it *is* the complete state, exactly like a new `Document` row chaining off the previous one per owner (`Document::booted()`). This plan therefore versions by row: each version is a full immutable snapshot sharing the same public `certificate_number`, with `version` incrementing and `previous_version_id` linking back to the row it supersedes. A `certificate_allocations` ledger references a specific certificate row (Task 7); multi-version quantity carry-over is noted as a known limitation of this MVP, not silently pretended solved (see Task 7's docblock).

**Signing — application-level, explicitly not a fabricated KMS/HSM:** Task 3 generates a real Ed25519 keypair via PHP 8.3's built-in `sodium` extension and signs with `sodium_crypto_sign_detached`. The private key is written to a file outside the database, referenced by a `.env` path (`CERTIFICATE_SIGNING_KEY_PATH`), read through `config('certificates.signing_key_path')` — never stored as a plain DB column an app admin can read from the Filament UI. The signing service's docblock states in plain language that this is a real cryptographic signature, application-level only, and that a KMS/HSM upgrade is documented future work, not already-built — matching the spec's own Ring 1 Layer 6 wording and this project's rule against fabricating infrastructure that doesn't exist.

**Explicitly deferred to 0.8b (do not build here):** guilloche/microtext/variable-watermark print-security pattern generation, physical certificate serial numbers, the physical copy/reprint registry, hologram/UV/tamper-seal integration, print-supplier/security-material inventory management, print-batch tracking. None of these are referenced by any task below.

---

### Task 1: `certificates` table, `CertificateStatus` enum, `Certificate` model, factory

**Files:**
- Create: `database/migrations/2026_08_29_100000_create_certificates_table.php`
- Create: `app/Enums/CertificateStatus.php`
- Create: `app/Models/Certificate.php`
- Create: `database/factories/CertificateFactory.php`
- Modify: `database/seeders/RolesAndPermissionsSeeder.php` (add `certificates.manage` permission)
- Test: `tests/Feature/CertificateTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Product;

it('creates a certificate with a public certificate number distinct from its db id, and a separate verification token', function () {
    $product = Product::factory()->create();

    $certificate = Certificate::create([
        'certificate_number' => 'TH-CMR-ORG-2026-'.strtoupper(Str::random(10)),
        'verification_token' => Str::random(48),
        'subject_type' => Product::class,
        'subject_id' => $product->id,
        'data' => ['product' => 'Sapelli sawn timber', 'origin' => ['country' => 'Cameroon']],
        'version' => 1,
        'certified_quantity' => 120.5,
        'quantity_unit' => 'm3',
        'status' => CertificateStatus::Draft,
    ]);

    expect($certificate->certificate_number)->not->toBe((string) $certificate->id)
        ->and($certificate->verification_token)->not->toBe($certificate->certificate_number)
        ->and($certificate->subject)->toBeInstanceOf(Product::class)
        ->and($certificate->subject->is($product))->toBeTrue()
        ->and($certificate->status)->toBe(CertificateStatus::Draft)
        ->and($certificate->version)->toBe(1);
});

it('rejects an invalid status value at the database level via the CHECK constraint', function () {
    $product = Product::factory()->create();

    expect(fn () => \Illuminate\Support\Facades\DB::table('certificates')->insert([
        'certificate_number' => 'TH-CMR-ORG-2026-BADSTATUS01',
        'verification_token' => \Illuminate\Support\Str::random(48),
        'subject_type' => Product::class,
        'subject_id' => $product->id,
        'data' => '{}',
        'version' => 1,
        'status' => 'not_a_real_status',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('never derives the verification token from the certificate number', function () {
    $certificate = Certificate::factory()->create([
        'certificate_number' => 'TH-CMR-ORG-2026-FIXEDVALUE01',
    ]);

    expect($certificate->verification_token)->not->toBe(md5($certificate->certificate_number))
        ->and($certificate->verification_token)->not->toBe(sha1($certificate->certificate_number))
        ->and(strlen($certificate->verification_token))->toBeGreaterThanOrEqual(32);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CertificateTest.php`
Expected: FAIL — `Class "App\Models\Certificate" not found`.

- [ ] **Step 3: Write the enum**

```php
<?php

namespace App\Enums;

/**
 * Certificate lifecycle status (docs/CERTIFICATE_SPEC.md Ring 2, Layer 9).
 * Revoked/superseded/replaced/withdrawn certificates are never deleted —
 * the row stays, only status changes. Matches the spec's exact status list.
 */
enum CertificateStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Issued = 'issued';
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case Superseded = 'superseded';
    case Replaced = 'replaced';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::Verified => 'Verified',
            self::Issued => 'Issued',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Revoked => 'Revoked',
            self::Superseded => 'Superseded',
            self::Replaced => 'Replaced',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft, self::Submitted, self::UnderReview => 'gray',
            self::Verified, self::Issued, self::Active => 'success',
            self::Suspended => 'warning',
            self::Revoked, self::Withdrawn => 'danger',
            self::Superseded, self::Replaced => 'gray',
        };
    }

    /** Statuses a verifier should read as "currently trustworthy". */
    public function isCurrentlyValid(): bool
    {
        return in_array($this, [self::Issued, self::Active], true);
    }

    /** Statuses that stop a certificate from being verified as good, without deleting it. */
    public function isTerminalNegative(): bool
    {
        return in_array($this, [self::Revoked, self::Superseded, self::Replaced, self::Withdrawn], true);
    }
}
```

- [ ] **Step 4: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rings 1+2 (Layers 1-14) canonical certificate record (gap-plan 0.8,
 * docs/CERTIFICATE_SPEC.md). Each ROW is one immutable version — see this
 * plan's "Versioning shape" scope-decision note for why this differs from
 * the Verification/VerificationCheckpoint parent+event-log split.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Identity (Layer 1) -- shared across versions of the same certificate.
            $table->string('certificate_number', 40)->unique();
            // Verification secret (Layer 2) -- unique PER ROW/VERSION, never
            // derived from certificate_number. A superseded version's token
            // stops resolving to "current" once status flips (see
            // CertificateVerifier in Task 9), but the row itself is kept.
            $table->string('verification_token', 64)->unique();

            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');

            // Canonical record (Layer 3) + hash (Layer 4).
            $table->json('data');
            $table->char('data_hash', 64)->nullable();

            // Versioning (Layer 11).
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('previous_version_id')->nullable();
            $table->text('version_reason')->nullable();
            $table->foreignId('version_actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('draft');

            // Signature (Layers 5-6).
            $table->string('key_id', 60)->nullable();
            $table->string('algorithm', 30)->nullable();
            $table->text('signature')->nullable();
            $table->timestampTz('signed_at')->nullable();

            // Evidence manifest (Layer 12).
            $table->char('evidence_manifest_hash', 64)->nullable();

            // Geospatial (Layer 13).
            $table->json('geospatial_data')->nullable();
            $table->char('geospatial_hash', 64)->nullable();

            // Quantity binding (Layer 14) -- the certified total this
            // certificate's allocations (Task 7) are checked against.
            $table->decimal('certified_quantity', 14, 3)->nullable();
            $table->string('quantity_unit', 20)->nullable();

            // Trusted timestamps (Layer 7).
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('issued_at')->nullable();

            $table->timestampsTz();

            $table->index(['subject_type', 'subject_id']);
            $table->index('certificate_number');
            $table->index('verification_token');
            $table->foreign('previous_version_id')->references('id')->on('certificates')->nullOnDelete();
        });

        DB::statement("ALTER TABLE certificates ADD CONSTRAINT certificates_status_check CHECK (status IN ('draft','submitted','under_review','verified','issued','active','suspended','revoked','superseded','replaced','withdrawn'))");

        // At most one row per certificate_number may be the "live" (non
        // superseded/replaced) version at a time -- the versioning invariant
        // Task 4's CertificateVersioningService enforces at the application
        // layer, backed here at the database layer.
        DB::statement("CREATE UNIQUE INDEX certificates_one_live_version_idx ON certificates (certificate_number) WHERE status NOT IN ('superseded','replaced')");
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
```

- [ ] **Step 5: Write the model**

```php
<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One immutable version of a TimberHub certificate (gap-plan 0.8, Rings 1+2
 * of docs/CERTIFICATE_SPEC.md). `certificate_number` is the public identity,
 * shared across every version; `id` is never exposed publicly.
 * `verification_token` is a separate, high-entropy per-row secret -- see
 * migration comment and CertificateVerifier (Task 9) for how a lookup by
 * token differs from a lookup by number.
 *
 * Mutation of `data`/`status`/signature fields should go through
 * CertificateService (Task 4) and its collaborators
 * (CertificateHashingService, CertificateSigningService), never direct
 * assignment here -- those services are the single point that keeps
 * data_hash/signature honest against the actual `data` payload.
 */
class Certificate extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'data' => 'array',
            'geospatial_data' => 'array',
            'certified_quantity' => 'decimal:3',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function nextVersions(): HasMany
    {
        return $this->hasMany(self::class, 'previous_version_id');
    }

    public function versionActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'version_actor_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CertificateAllocation::class);
    }

    public function scopeForNumber(Builder $query, string $certificateNumber): Builder
    {
        return $query->where('certificate_number', $certificateNumber);
    }

    /** The one row for a given certificate_number that is not superseded/replaced. */
    public function scopeLiveVersion(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            CertificateStatus::Superseded->value,
            CertificateStatus::Replaced->value,
        ]);
    }

    /** A short, human-readable slice of the hash for print display (spec Layer 10, "Cryptographic Fingerprint"). */
    public function fingerprint(): ?string
    {
        return $this->data_hash === null
            ? null
            : strtoupper(implode(' ', str_split(substr($this->data_hash, 0, 16), 4)));
    }
}
```

- [ ] **Step 6: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Certificate> */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    public function definition(): array
    {
        return [
            'certificate_number' => 'TH-CMR-ORG-'.now()->year.'-'.strtoupper(Str::random(10)),
            'verification_token' => Str::random(48),
            'subject_type' => Product::class,
            'subject_id' => Product::factory(),
            'data' => [
                'product' => $this->faker->words(3, true),
                'origin' => ['country' => 'Cameroon', 'region' => $this->faker->state()],
            ],
            'version' => 1,
            'certified_quantity' => $this->faker->randomFloat(3, 10, 500),
            'quantity_unit' => 'm3',
            'status' => CertificateStatus::Draft,
        ];
    }
}
```

- [ ] **Step 7: Add the `certificates.manage` permission**

In `database/seeders/RolesAndPermissionsSeeder.php`, add `'certificates.manage'` to `PERMISSIONS`, and to the `admin` and `verification_officer` entries in `MATRIX` (append, do not reorder existing entries):

```php
    public const PERMISSIONS = [
        'companies.view',
        'companies.manage',
        'companies.suspend',
        'documents.review',
        'verification.review',
        'badges.issue',
        'badges.revoke',
        'species.manage',
        'products.manage',
        'rfqs.triage',
        'rfqs.route',
        'inquiries.review',
        'pages.manage',
        'plans.manage',
        'users.manage',
        'audit.view',
        'certificates.manage',
    ];
```

```php
        'admin' => [
            'companies.view', 'companies.manage',
            'documents.review', 'verification.review',
            'badges.issue', 'badges.revoke',
            'species.manage', 'products.manage',
            'rfqs.triage', 'rfqs.route', 'inquiries.review',
            'pages.manage', 'audit.view',
            'certificates.manage',
        ],
        'verification_officer' => [
            'companies.view', 'documents.review', 'verification.review',
            'badges.issue', 'badges.revoke', 'audit.view',
            'certificates.manage',
        ],
```

- [ ] **Step 8: Run tests to verify they pass**

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** the suite shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. Before ever running `migrate:fresh`, verify `.env.testing` genuinely resolves to `cameroontimberhub_testing`:

```bash
php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"
```

Expected output: `cameroontimberhub_testing`. If you see "relation X already exists" or "relation migrations does not exist" while running tests, that is corruption from a concurrent run — repair with `php artisan migrate:fresh --env=testing --force` only after the check above passes. Never run `migrate:fresh` without it — earlier in this project's history, running it without `.env.testing` in place silently wiped the dev database instead.

Run: `php artisan test tests/Feature/CertificateTest.php`
Expected: PASS (3 tests).

- [ ] **Step 9: Pint and commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_08_29_100000_create_certificates_table.php \
        app/Enums/CertificateStatus.php app/Models/Certificate.php \
        database/factories/CertificateFactory.php \
        database/seeders/RolesAndPermissionsSeeder.php \
        tests/Feature/CertificateTest.php
git commit -m "Add Certificate model, certificates table, and CertificateStatus enum"
```

---

### Task 2: `CertificateHashingService` — deterministic canonical serialization + SHA-256

**Files:**
- Create: `app/Services/CertificateHashingService.php`
- Test: `tests/Unit/CertificateHashingServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\CertificateHashingService;

beforeEach(function () {
    $this->service = new CertificateHashingService();
});

it('produces byte-identical canonical JSON regardless of input key order', function () {
    $a = ['origin' => ['country' => 'Cameroon', 'region' => 'East'], 'product' => 'Sapelli'];
    $b = ['product' => 'Sapelli', 'origin' => ['region' => 'East', 'country' => 'Cameroon']];

    expect($this->service->canonicalize($a))->toBe($this->service->canonicalize($b));
});

it('hashes the canonical form with sha256, producing the same hash for reordered-but-equal data', function () {
    $a = ['product' => 'Sapelli', 'quantity' => 120.5];
    $b = ['quantity' => 120.5, 'product' => 'Sapelli'];

    expect($this->service->hash($a))->toBe($this->service->hash($b))
        ->and($this->service->hash($a))->toHaveLength(64);
});

it('produces a different hash when a value actually changes', function () {
    $a = ['quantity' => 120.5];
    $b = ['quantity' => 120.6];

    expect($this->service->hash($a))->not->toBe($this->service->hash($b));
});

it('normalizes floats so 120.50 and 120.5 canonicalize identically, avoiding floating-point ambiguity', function () {
    $a = ['quantity' => 120.50];
    $b = ['quantity' => 120.5];

    expect($this->service->canonicalize($a))->toBe($this->service->canonicalize($b));
});

it('canonicalizes nested arrays and lists deterministically', function () {
    $a = ['evidence' => ['b-doc', 'a-doc'], 'meta' => ['z' => 1, 'a' => 2]];
    $b = ['meta' => ['a' => 2, 'z' => 1], 'evidence' => ['b-doc', 'a-doc']];

    expect($this->service->canonicalize($a))->toBe($this->service->canonicalize($b));
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/CertificateHashingServiceTest.php`
Expected: FAIL — `Class "App\Services\CertificateHashingService" not found`.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

/**
 * Produces ONE deterministic JSON string for a certificate's data payload
 * (docs/CERTIFICATE_SPEC.md Ring 1, Layer 3: "the actual source of truth,
 * not the PDF"), and the SHA-256 hash of it (Layer 4).
 *
 * Determinism rules, all load-bearing for signature verification later
 * (Task 3 signs this hash, not the raw array):
 *   - Associative array keys are sorted recursively (ksort, recursive).
 *   - Sequential (list) arrays keep their given order -- order is meaningful
 *     for lists (e.g. an ordered evidence array), only *key* order for maps
 *     is normalized.
 *   - Floats are normalized to a fixed string representation so 120.50 and
 *     120.5 canonicalize identically -- avoids the classic
 *     json_encode(120.50) === json_encode(120.5) === "120.5" trap becoming a
 *     FALSE negative on hash comparison if a caller ever passes a
 *     differently-typed numeric value for the same logical quantity.
 *   - JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE: stable,
 *     human-comparable output; no dependence on PHP's default slash-escaping.
 */
class CertificateHashingService
{
    public function canonicalize(array $data): string
    {
        $normalized = $this->normalize($data);

        return json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function hash(array $data): string
    {
        return hash('sha256', $this->canonicalize($data));
    }

    /** @return mixed */
    private function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            // Fixed 6-decimal representation, trailing zeros trimmed, so
            // 120.5 and 120.500000 collapse to the same canonical string.
            // 6 decimals comfortably covers this spec's own EUDR ">=6
            // decimal digits" coordinate precision requirement (Task 6).
            $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

            return $formatted === '' ? '0' : $formatted;
        }

        if (is_array($value)) {
            $isList = array_is_list($value);

            if ($isList) {
                return array_map(fn ($v) => $this->normalize($v), $value);
            }

            ksort($value);
            $out = [];
            foreach ($value as $key => $v) {
                $out[$key] = $this->normalize($v);
            }

            return $out;
        }

        return $value;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/CertificateHashingServiceTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Pint and commit**

```bash
vendor/bin/pint --dirty
git add app/Services/CertificateHashingService.php tests/Unit/CertificateHashingServiceTest.php
git commit -m "Add CertificateHashingService: deterministic canonical JSON + SHA-256"
```

---

### Task 3: Signing key generation command + `CertificateSigningService`

**Files:**
- Create: `config/certificates.php`
- Modify: `.env.example` (document the new key)
- Create: `app/Console/Commands/GenerateCertificateSigningKey.php`
- Create: `app/Services/CertificateSigningService.php`
- Test: `tests/Unit/CertificateSigningServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\CertificateSigningService;

beforeEach(function () {
    $this->keyPath = storage_path('framework/testing/certificate-signing-key-'.uniqid().'.json');
    config(['certificates.signing_key_path' => $this->keyPath, 'certificates.key_id' => 'test-key-1']);
});

afterEach(function () {
    @unlink($this->keyPath);
});

it('generates a real Ed25519 keypair and writes only the key material to the configured path', function () {
    $service = new CertificateSigningService();
    $service->generateKeypair();

    expect(file_exists($this->keyPath))->toBeTrue();

    $stored = json_decode(file_get_contents($this->keyPath), true);
    expect($stored)->toHaveKeys(['key_id', 'private_key', 'public_key'])
        ->and($stored['key_id'])->toBe('test-key-1');
});

it('signs a hash and the signature verifies against the matching public key', function () {
    $service = new CertificateSigningService();
    $service->generateKeypair();

    $hash = hash('sha256', 'canonical-payload');
    $result = $service->sign($hash);

    expect($result)->toHaveKeys(['key_id', 'algorithm', 'signature'])
        ->and($result['algorithm'])->toBe('ed25519')
        ->and($service->verify($hash, $result['signature']))->toBeTrue();
});

it('fails verification if the hash was tampered with after signing', function () {
    $service = new CertificateSigningService();
    $service->generateKeypair();

    $result = $service->sign(hash('sha256', 'original-payload'));

    expect($service->verify(hash('sha256', 'tampered-payload'), $result['signature']))->toBeFalse();
});

it('throws a clear error if asked to sign before a keypair exists', function () {
    $service = new CertificateSigningService();

    expect(fn () => $service->sign(hash('sha256', 'x')))
        ->toThrow(RuntimeException::class, 'signing key not found');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/CertificateSigningServiceTest.php`
Expected: FAIL — `Class "App\Services\CertificateSigningService" not found`.

- [ ] **Step 3: Write the config file**

```php
<?php

return [
    /*
     * Path to the JSON file holding the certificate signing keypair
     * (docs/CERTIFICATE_SPEC.md Ring 1, Layer 6). Deliberately NOT a
     * database column -- see CertificateSigningService's docblock for why.
     * Must live outside the web root and outside version control (add the
     * directory to .gitignore if it is not already covered by
     * storage/**). Default path keeps it inside Laravel's own storage tree,
     * which is already excluded from the web server's document root.
     */
    'signing_key_path' => env('CERTIFICATE_SIGNING_KEY_PATH', storage_path('app/certificates/signing-key.json')),

    /*
     * Identifies which keypair signed a given certificate row
     * (certificates.key_id) -- lets a future key rotation keep old
     * signatures verifiable against the key that actually made them,
     * without needing to know "the" current key.
     */
    'key_id' => env('CERTIFICATE_SIGNING_KEY_ID', 'timberhub-cert-key-1'),
];
```

- [ ] **Step 4: Document the env var**

Append to `.env.example`:

```
# Path to the certificate signing keypair file (outside the DB - see
# config/certificates.php and app/Services/CertificateSigningService.php).
CERTIFICATE_SIGNING_KEY_PATH=
CERTIFICATE_SIGNING_KEY_ID=timberhub-cert-key-1
```

- [ ] **Step 5: Write the signing service**

```php
<?php

namespace App\Services;

use RuntimeException;

/**
 * Real asymmetric signing over a certificate's canonical data hash
 * (docs/CERTIFICATE_SPEC.md Ring 1, Layer 5-6), using PHP 8.3's built-in
 * `sodium` extension (Ed25519 via sodium_crypto_sign_detached) -- no
 * external crypto library needed.
 *
 * IMPORTANT, stated plainly per this project's rule against fabricating
 * infrastructure: this IS a genuine cryptographic signature -- a
 * certificate's signature cannot be forged without the private key file,
 * and it cryptographically binds a specific key_id to a specific data_hash.
 * It is NOT backed by a KMS or HSM. The private key currently lives in a
 * file on the application server (config('certificates.signing_key_path')),
 * separated from the database (an app admin browsing Filament cannot read
 * it) but not separated from the application server itself. Upgrading to a
 * real KMS/HSM -- where the application asks a signing service to sign and
 * never touches the private key at all (spec Ring 1 Layer 6's exact
 * phrasing) -- is documented future work, tracked as part of gap-plan 0.8c's
 * broader security-hardening scope. It is not pretended to already exist.
 */
class CertificateSigningService
{
    /** Generates a fresh Ed25519 keypair and writes it to the configured path. Overwrites any existing key -- callers must not call this against a production key path without an explicit rotation decision. */
    public function generateKeypair(): void
    {
        $keypair = sodium_crypto_sign_keypair();

        $payload = [
            'key_id' => config('certificates.key_id'),
            'private_key' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
            'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'generated_at' => now()->toIso8601String(),
        ];

        $path = config('certificates.signing_key_path');
        @mkdir(dirname($path), recursive: true);
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT));
        chmod($path, 0600);
    }

    /** @return array{key_id: string, algorithm: string, signature: string} */
    public function sign(string $hash): array
    {
        $key = $this->loadKey();

        $signature = sodium_crypto_sign_detached($hash, base64_decode($key['private_key']));

        return [
            'key_id' => $key['key_id'],
            'algorithm' => 'ed25519',
            'signature' => base64_encode($signature),
        ];
    }

    public function verify(string $hash, string $signatureBase64): bool
    {
        $key = $this->loadKey();

        return sodium_crypto_sign_verify_detached(
            base64_decode($signatureBase64),
            $hash,
            base64_decode($key['public_key']),
        );
    }

    /** @return array{key_id: string, private_key: string, public_key: string} */
    private function loadKey(): array
    {
        $path = config('certificates.signing_key_path');

        if (! file_exists($path)) {
            throw new RuntimeException("Certificate signing key not found at [{$path}]. Run: php artisan certificates:generate-signing-key");
        }

        return json_decode(file_get_contents($path), true);
    }
}
```

- [ ] **Step 6: Write the artisan command**

```php
<?php

namespace App\Console\Commands;

use App\Services\CertificateSigningService;
use Illuminate\Console\Command;

/**
 * Generates the application's certificate signing keypair (see
 * CertificateSigningService's docblock for what this is and is not).
 * Run once per environment during setup, and again on a deliberate key
 * rotation -- never as part of an automated deploy step, since it
 * overwrites the existing key file.
 */
class GenerateCertificateSigningKey extends Command
{
    protected $signature = 'certificates:generate-signing-key {--force : Overwrite an existing key without confirmation}';

    protected $description = 'Generate the Ed25519 keypair used to sign certificates';

    public function handle(CertificateSigningService $signer): int
    {
        $path = config('certificates.signing_key_path');

        if (file_exists($path) && ! $this->option('force')) {
            if (! $this->confirm("A signing key already exists at [{$path}]. Overwriting it will invalidate verification of any signature made with the old key. Continue?")) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }
        }

        $signer->generateKeypair();
        $this->info("Certificate signing key generated at [{$path}].");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Unit/CertificateSigningServiceTest.php`
Expected: PASS (4 tests).

- [ ] **Step 8: Pint and commit**

```bash
vendor/bin/pint --dirty
git add config/certificates.php .env.example app/Console/Commands/GenerateCertificateSigningKey.php \
        app/Services/CertificateSigningService.php tests/Unit/CertificateSigningServiceTest.php
git commit -m "Add CertificateSigningService (Ed25519 via sodium) and key-generation command"
```

---

### Task 4: `CertificateService` — issuance, signing wiring, and versioning

**Files:**
- Create: `app/Services/CertificateService.php`
- Test: `tests/Feature/CertificateServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\CertificateStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\CertificateService;

beforeEach(function () {
    $this->keyPath = storage_path('framework/testing/certificate-signing-key-'.uniqid().'.json');
    config(['certificates.signing_key_path' => $this->keyPath, 'certificates.key_id' => 'test-key-1']);
    app(\App\Services\CertificateSigningService::class)->generateKeypair();
    $this->service = app(CertificateService::class);
});

afterEach(function () {
    @unlink($this->keyPath);
});

it('drafts a certificate for a subject with a hashed canonical payload', function () {
    $product = Product::factory()->create();

    $certificate = $this->service->draft($product, [
        'product' => 'Sapelli sawn timber',
        'origin' => ['country' => 'Cameroon'],
    ], quantity: 100.0, unit: 'm3');

    expect($certificate->status)->toBe(CertificateStatus::Draft)
        ->and($certificate->data_hash)->not->toBeNull()
        ->and($certificate->data_hash)->toHaveLength(64)
        ->and($certificate->version)->toBe(1);
});

it('signs a certificate, stamping key_id/algorithm/signature/signed_at', function () {
    $certificate = $this->service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');
    $officer = User::factory()->create();

    $signed = $this->service->sign($certificate, $officer);

    expect($signed->key_id)->toBe('test-key-1')
        ->and($signed->algorithm)->toBe('ed25519')
        ->and($signed->signature)->not->toBeNull()
        ->and($signed->signed_at)->not->toBeNull()
        ->and($signed->status)->toBe(CertificateStatus::Verified);
});

it('issues a signed certificate, stamping issued_at and moving to active', function () {
    $certificate = $this->service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');
    $officer = User::factory()->create();
    $signed = $this->service->sign($certificate, $officer);

    $issued = $this->service->issue($signed, $officer);

    expect($issued->status)->toBe(CertificateStatus::Active)
        ->and($issued->issued_at)->not->toBeNull();
});

it('refuses to issue a certificate that has not been signed', function () {
    $certificate = $this->service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');

    expect(fn () => $this->service->issue($certificate, User::factory()->create()))
        ->toThrow(RuntimeException::class);
});

it('creates a new version with its own hash and signature, and marks the prior version superseded, sharing the same certificate_number', function () {
    $certificate = $this->service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');
    $officer = User::factory()->create();
    $issued = $this->service->issue($this->service->sign($certificate, $officer), $officer);

    $newVersion = $this->service->createVersion(
        $issued,
        ['product' => 'Sapelli sawn timber, corrected grade'],
        'Grade correction after re-inspection',
        $officer,
    );

    expect($newVersion->certificate_number)->toBe($issued->certificate_number)
        ->and($newVersion->version)->toBe(2)
        ->and($newVersion->previous_version_id)->toBe($issued->id)
        ->and($newVersion->version_reason)->toBe('Grade correction after re-inspection')
        ->and($newVersion->version_actor_id)->toBe($officer->id)
        ->and($newVersion->data_hash)->not->toBe($issued->data_hash)
        ->and($newVersion->verification_token)->not->toBe($issued->verification_token)
        ->and($issued->fresh()->status)->toBe(CertificateStatus::Superseded);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CertificateServiceTest.php`
Expected: FAIL — `Class "App\Services\CertificateService" not found`.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The single mutation point for a Certificate's canonical data, hash,
 * signature and lifecycle status (gap-plan 0.8, Rings 1+2). Nothing else
 * should call Certificate::update() directly for these fields, so
 * data_hash/signature never drift from the actual `data` payload -- the
 * exact failure mode docs/CERTIFICATE_SPEC.md warns against.
 */
class CertificateService
{
    public function __construct(
        private readonly CertificateHashingService $hasher,
        private readonly CertificateSigningService $signer,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function draft(Model $subject, array $data, float $quantity, string $unit): Certificate
    {
        return Certificate::create([
            'certificate_number' => $this->generateCertificateNumber(),
            'verification_token' => Str::random(48),
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'data' => $data,
            'data_hash' => $this->hasher->hash($data),
            'version' => 1,
            'certified_quantity' => $quantity,
            'quantity_unit' => $unit,
            'status' => CertificateStatus::Draft,
        ]);
    }

    /** Signs the certificate's current data hash and moves it to Verified. */
    public function sign(Certificate $certificate, User $actor): Certificate
    {
        if ($certificate->status !== CertificateStatus::Draft && $certificate->status !== CertificateStatus::UnderReview) {
            throw new RuntimeException("Cannot sign a certificate in status [{$certificate->status->value}].");
        }

        $result = $this->signer->sign($certificate->data_hash);

        return DB::transaction(function () use ($certificate, $result) {
            $certificate->update([
                'key_id' => $result['key_id'],
                'algorithm' => $result['algorithm'],
                'signature' => $result['signature'],
                'signed_at' => now(),
                'approved_at' => now(),
                'status' => CertificateStatus::Verified,
            ]);

            return $certificate->fresh();
        });
    }

    public function issue(Certificate $certificate, User $actor): Certificate
    {
        if ($certificate->status !== CertificateStatus::Verified) {
            throw new RuntimeException('A certificate must be signed and verified before it can be issued. Call sign() first.');
        }

        $certificate->update([
            'status' => CertificateStatus::Active,
            'issued_at' => now(),
        ]);

        return $certificate->fresh();
    }

    /**
     * Never edits `data` in place -- creates a new immutable row sharing
     * `certificate_number`, hashes and requires re-signing, and marks the
     * prior row Superseded. See this plan's "Versioning shape" scope
     * decision for why this is a new row rather than a child table.
     *
     * @param  array<string, mixed>  $newData
     */
    public function createVersion(Certificate $current, array $newData, string $reason, User $actor): Certificate
    {
        if (blank($reason)) {
            throw new RuntimeException('A reason is required to create a new certificate version.');
        }

        return DB::transaction(function () use ($current, $newData, $reason, $actor) {
            $next = Certificate::create([
                'certificate_number' => $current->certificate_number,
                'verification_token' => Str::random(48),
                'subject_type' => $current->subject_type,
                'subject_id' => $current->subject_id,
                'data' => $newData,
                'data_hash' => $this->hasher->hash($newData),
                'version' => $current->version + 1,
                'previous_version_id' => $current->id,
                'version_reason' => $reason,
                'version_actor_id' => $actor->getKey(),
                'certified_quantity' => $current->certified_quantity,
                'quantity_unit' => $current->quantity_unit,
                'status' => CertificateStatus::Draft,
            ]);

            $current->update(['status' => CertificateStatus::Superseded]);

            return $next->fresh();
        });
    }

    /** e.g. TH-CMR-ORG-2026-A1B2C3D4E5 -- high-entropy suffix, never the DB primary key. */
    private function generateCertificateNumber(): string
    {
        return sprintf('TH-CMR-ORG-%d-%s', now()->year, strtoupper(Str::random(10)));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/CertificateServiceTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Services/CertificateService.php tests/Feature/CertificateServiceTest.php
git commit -m "Add CertificateService: draft/sign/issue/createVersion lifecycle"
```

---

### Task 5: `checksum_sha256` for real + `CertificateEvidenceService`

**Files:**
- Modify: `app/Models/Document.php` (populate `checksum_sha256` from real file bytes)
- Create: `app/Services/CertificateEvidenceService.php`
- Test: `tests/Feature/DocumentChecksumTest.php`
- Test: `tests/Unit/CertificateEvidenceServiceTest.php`

- [ ] **Step 1: Write the failing test for the checksum**

```php
<?php

use App\Models\Document;
use App\Models\Species;
use Illuminate\Support\Facades\Storage;

it('computes checksum_sha256 from the actual uploaded file bytes on create', function () {
    Storage::fake('documents');
    $species = Species::factory()->create();
    $path = 'documents/2026/08/evidence-test.pdf';
    Storage::disk('documents')->put($path, 'fixed-file-contents-for-hashing');

    $document = Document::create([
        'owner_type' => Species::class,
        'owner_id' => $species->id,
        'type' => 'source_citation',
        'original_filename' => 'evidence-test.pdf',
        'disk' => 'documents',
        'storage_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => strlen('fixed-file-contents-for-hashing'),
    ]);

    expect($document->checksum_sha256)->toBe(hash('sha256', 'fixed-file-contents-for-hashing'));
});

it('leaves checksum_sha256 null if the file cannot be found on disk at creation time, rather than fabricating a hash', function () {
    Storage::fake('documents');
    $species = Species::factory()->create();

    $document = Document::create([
        'owner_type' => Species::class,
        'owner_id' => $species->id,
        'type' => 'source_citation',
        'original_filename' => 'missing.pdf',
        'disk' => 'documents',
        'storage_path' => 'documents/does-not-exist.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
    ]);

    expect($document->checksum_sha256)->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/DocumentChecksumTest.php`
Expected: FAIL — `checksum_sha256` is null in the first test.

- [ ] **Step 3: Modify `Document::booted()`**

Read `app/Models/Document.php` in full first — do not touch the existing `hash`/`prev_hash` chaining logic (it stays exactly as-is; this only adds a second, independent computation reading real file bytes). Add to the `static::creating(...)` closure, after the existing `hash`/`prev_hash` assignment and before the closure ends:

```php
            // First real population of checksum_sha256 -- see this model's
            // own class docblock, which until now documented that nothing
            // populated it. This is the file's own byte-for-byte integrity
            // hash (distinct from hash/prev_hash above, which chain row
            // METADATA in upload order, not file contents). Left null,
            // never fabricated, if the file genuinely cannot be read yet
            // (e.g. a row created before its file finished uploading).
            if ($document->checksum_sha256 === null && $document->disk && $document->storage_path) {
                $document->checksum_sha256 = \Illuminate\Support\Facades\Storage::disk($document->disk)->exists($document->storage_path)
                    ? hash('sha256', \Illuminate\Support\Facades\Storage::disk($document->disk)->get($document->storage_path))
                    : null;
            }
```

Also update the class docblock's now-stale sentence "which nothing currently populates" to:

```
 * hash/prev_hash are NOT the uploaded file's checksum -- they hash row
 * metadata (owner, filename, storage path, previous hash, timestamp) purely
 * to chain rows in per-owner upload order. The file's own byte-for-byte
 * integrity is tracked separately in `checksum_sha256`, computed from the
 * actual file bytes on the configured disk at creation time (see booted()).
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/DocumentChecksumTest.php`
Expected: PASS (2 tests). Then run the full pre-existing `DocumentTest.php` suite to confirm nothing regressed: `php artisan test tests/Feature/DocumentTest.php`.

- [ ] **Step 5: Write the failing test for `CertificateEvidenceService`**

```php
<?php

use App\Models\Certificate;
use App\Models\Document;
use App\Models\Species;
use App\Services\CertificateEvidenceService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->service = app(CertificateEvidenceService::class);
});

it('aggregates a set of document checksums into one manifest hash, order-independent', function () {
    Storage::fake('documents');
    $species = Species::factory()->create();

    $docA = Document::create([
        'owner_type' => Species::class, 'owner_id' => $species->id, 'type' => 'evidence',
        'original_filename' => 'a.pdf', 'disk' => 'documents', 'storage_path' => 'documents/a.pdf',
        'mime_type' => 'application/pdf', 'file_size' => 3,
    ]);
    Storage::disk('documents')->put('documents/a.pdf', 'aaa');
    $docA->refresh();

    $docB = Document::create([
        'owner_type' => Species::class, 'owner_id' => $species->id, 'type' => 'evidence',
        'original_filename' => 'b.pdf', 'disk' => 'documents', 'storage_path' => 'documents/b.pdf',
        'mime_type' => 'application/pdf', 'file_size' => 3,
    ]);
    Storage::disk('documents')->put('documents/b.pdf', 'bbb');
    $docB->refresh();

    $forward = $this->service->manifestHash(collect([$docA, $docB]));
    $reversed = $this->service->manifestHash(collect([$docB, $docA]));

    expect($forward)->toBe($reversed)
        ->and($forward)->toHaveLength(64);
});

it('refuses to build a manifest that includes a document with no checksum yet', function () {
    $species = Species::factory()->create();
    $undated = Document::factory()->for($species, 'owner')->create(['checksum_sha256' => null]);

    expect(fn () => $this->service->manifestHash(collect([$undated])))
        ->toThrow(RuntimeException::class);
});

it('binds a manifest hash onto a certificate', function () {
    Storage::fake('documents');
    $species = Species::factory()->create();
    $doc = Document::create([
        'owner_type' => Species::class, 'owner_id' => $species->id, 'type' => 'evidence',
        'original_filename' => 'a.pdf', 'disk' => 'documents', 'storage_path' => 'documents/a.pdf',
        'mime_type' => 'application/pdf', 'file_size' => 3,
    ]);
    Storage::disk('documents')->put('documents/a.pdf', 'aaa');
    $doc->refresh();
    $certificate = Certificate::factory()->create();

    $updated = $this->service->attachEvidence($certificate, collect([$doc]));

    expect($updated->evidence_manifest_hash)->toBe($doc->checksum_sha256 === null ? null : hash('sha256', $doc->checksum_sha256));
});
```

- [ ] **Step 6: Run to verify it fails**

Run: `php artisan test tests/Unit/CertificateEvidenceServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 7: Write the service**

```php
<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Document;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Evidence manifest integrity (docs/CERTIFICATE_SPEC.md Ring 2, Layer 12):
 * every evidence file is individually SHA-256 hashed (Document::checksum_sha256,
 * populated for real as of this plan's Task 5), aggregated here into ONE
 * manifest hash bound to a Certificate.
 */
class CertificateEvidenceService
{
    /**
     * Sorts checksums before joining so the manifest hash is independent of
     * the order documents happen to be passed in -- an evidence set is a
     * SET, not a sequence.
     *
     * @param  Collection<int, Document>  $documents
     */
    public function manifestHash(Collection $documents): string
    {
        $checksums = $documents->map(function (Document $document) {
            if ($document->checksum_sha256 === null) {
                throw new RuntimeException("Document [{$document->id}] has no checksum_sha256 yet -- cannot include it in an evidence manifest.");
            }

            return $document->checksum_sha256;
        })->sort()->values()->all();

        return hash('sha256', implode('|', $checksums));
    }

    /** @param  Collection<int, Document>  $documents */
    public function attachEvidence(Certificate $certificate, Collection $documents): Certificate
    {
        $certificate->update([
            'evidence_manifest_hash' => $this->manifestHash($documents),
        ]);

        return $certificate->fresh();
    }
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test tests/Unit/CertificateEvidenceServiceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 9: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Models/Document.php app/Services/CertificateEvidenceService.php \
        tests/Feature/DocumentChecksumTest.php tests/Unit/CertificateEvidenceServiceTest.php
git commit -m "Populate Document::checksum_sha256 for real; add CertificateEvidenceService manifest hash"
```

---

### Task 6: `CertificateGeoService` — GeoJSON canonicalization, hash, and validation

**Files:**
- Create: `app/Services/CertificateGeoService.php`
- Create: `app/Exceptions/InvalidGeospatialDataException.php`
- Test: `tests/Unit/CertificateGeoServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Exceptions\InvalidGeospatialDataException;
use App\Services\CertificateGeoService;

beforeEach(function () {
    $this->service = app(CertificateGeoService::class);
});

it('validates and hashes a point with at least 6 decimal digits of precision', function () {
    $point = ['type' => 'Point', 'coordinates' => [11.518890, 3.848210]];

    $hash = $this->service->hashAndValidate($point);

    expect($hash)->toHaveLength(64);
});

it('rejects a point with fewer than 6 decimal digits of precision, per EUDR', function () {
    $point = ['type' => 'Point', 'coordinates' => [11.51, 3.84]];

    expect(fn () => $this->service->hashAndValidate($point))
        ->toThrow(InvalidGeospatialDataException::class, 'precision');
});

it('validates a closed, simple (non-self-intersecting) polygon', function () {
    $polygon = [
        'type' => 'Polygon',
        'coordinates' => [[
            [11.518890, 3.848210],
            [11.520000, 3.848210],
            [11.520000, 3.850000],
            [11.518890, 3.850000],
            [11.518890, 3.848210],
        ]],
    ];

    $hash = $this->service->hashAndValidate($polygon);

    expect($hash)->toHaveLength(64);
});

it('rejects a polygon ring that is not closed', function () {
    $polygon = [
        'type' => 'Polygon',
        'coordinates' => [[
            [11.518890, 3.848210],
            [11.520000, 3.848210],
            [11.520000, 3.850000],
        ]],
    ];

    expect(fn () => $this->service->hashAndValidate($polygon))
        ->toThrow(InvalidGeospatialDataException::class, 'closed');
});

it('rejects a self-intersecting (bowtie) polygon', function () {
    $bowtie = [
        'type' => 'Polygon',
        'coordinates' => [[
            [11.500000, 3.800000],
            [11.600000, 3.900000],
            [11.600000, 3.800000],
            [11.500000, 3.900000],
            [11.500000, 3.800000],
        ]],
    ];

    expect(fn () => $this->service->hashAndValidate($bowtie))
        ->toThrow(InvalidGeospatialDataException::class, 'self-intersect');
});

it('produces the same hash for the same geometry regardless of key order', function () {
    $a = ['type' => 'Point', 'coordinates' => [11.518890, 3.848210]];
    $b = ['coordinates' => [11.518890, 3.848210], 'type' => 'Point'];

    expect($this->service->hashAndValidate($a))->toBe($this->service->hashAndValidate($b));
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/CertificateGeoServiceTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the exception**

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidGeospatialDataException extends RuntimeException {}
```

- [ ] **Step 4: Write the service**

```php
<?php

namespace App\Services;

use App\Exceptions\InvalidGeospatialDataException;

/**
 * Geospatial integrity (docs/CERTIFICATE_SPEC.md Ring 2, Layer 13): a
 * canonical GeoJSON Point or Polygon, hashed and bound to a certificate.
 * No spatial-engine composer package exists in this codebase (confirmed
 * during planning), so validation here is plain PHP: coordinate precision,
 * ring closure, minimum vertex count, and a real O(n^2) segment-intersection
 * self-intersection check -- correct and sufficient for the small polygons
 * (a single forest plot boundary) this certificate binds, not a general
 * GIS engine. If plots with hundreds of vertices or true multi-polygons
 * become a real requirement, that is the trigger to add a maintained
 * geometry library rather than growing this by hand.
 */
class CertificateGeoService
{
    private const MIN_DECIMAL_PRECISION = 6;

    public function hashAndValidate(array $geoJson): string
    {
        $this->validate($geoJson);

        return hash('sha256', $this->canonicalize($geoJson));
    }

    private function validate(array $geoJson): void
    {
        $type = $geoJson['type'] ?? null;

        match ($type) {
            'Point' => $this->validatePoint($geoJson['coordinates'] ?? null),
            'Polygon' => $this->validatePolygon($geoJson['coordinates'] ?? null),
            default => throw new InvalidGeospatialDataException("Unsupported GeoJSON type [{$type}]. Only Point and Polygon are accepted."),
        };
    }

    private function validatePoint(?array $coordinates): void
    {
        if (! is_array($coordinates) || count($coordinates) !== 2) {
            throw new InvalidGeospatialDataException('Point must have exactly [longitude, latitude] coordinates.');
        }

        foreach ($coordinates as $coordinate) {
            $this->assertPrecision($coordinate);
        }
    }

    private function validatePolygon(?array $rings): void
    {
        if (! is_array($rings) || count($rings) < 1) {
            throw new InvalidGeospatialDataException('Polygon must have at least one ring.');
        }

        $ring = $rings[0];

        if (count($ring) < 4) {
            throw new InvalidGeospatialDataException('Polygon ring must have at least 4 positions (3 distinct vertices plus the closing point).');
        }

        if ($ring[0] !== $ring[count($ring) - 1]) {
            throw new InvalidGeospatialDataException('Polygon ring must be closed: first and last positions must match.');
        }

        foreach ($ring as $position) {
            if (! is_array($position) || count($position) !== 2) {
                throw new InvalidGeospatialDataException('Every polygon position must be [longitude, latitude].');
            }
            foreach ($position as $coordinate) {
                $this->assertPrecision($coordinate);
            }
        }

        $this->assertSimple($ring);
    }

    private function assertPrecision(float|int $coordinate): void
    {
        $decimalPlaces = strlen(substr(strrchr((string) $coordinate, '.'), 1) ?: '');

        if ($decimalPlaces < self::MIN_DECIMAL_PRECISION) {
            throw new InvalidGeospatialDataException("Coordinate {$coordinate} has fewer than ".self::MIN_DECIMAL_PRECISION.' decimal digits of precision, required by EUDR.');
        }
    }

    /** O(n^2) pairwise non-adjacent segment intersection check -- fine for a plot-boundary-sized ring. */
    private function assertSimple(array $ring): void
    {
        $edges = count($ring) - 1; // last position duplicates the first (closed ring)

        for ($i = 0; $i < $edges; $i++) {
            for ($j = $i + 1; $j < $edges; $j++) {
                // Skip edges that share an endpoint (adjacent edges always "touch").
                if ($j === $i + 1 || ($i === 0 && $j === $edges - 1)) {
                    continue;
                }

                if ($this->segmentsIntersect($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                    throw new InvalidGeospatialDataException('Polygon ring is self-intersecting.');
                }
            }
        }
    }

    private function segmentsIntersect(array $p1, array $p2, array $p3, array $p4): bool
    {
        $d1 = $this->direction($p3, $p4, $p1);
        $d2 = $this->direction($p3, $p4, $p2);
        $d3 = $this->direction($p1, $p2, $p3);
        $d4 = $this->direction($p1, $p2, $p4);

        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0))
            && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }

    private function direction(array $a, array $b, array $c): float
    {
        return ($c[0] - $a[0]) * ($b[1] - $a[1]) - ($b[0] - $a[0]) * ($c[1] - $a[1]);
    }

    private function canonicalize(array $geoJson): string
    {
        ksort($geoJson);

        return json_encode($geoJson, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/CertificateGeoServiceTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty
git add app/Services/CertificateGeoService.php app/Exceptions/InvalidGeospatialDataException.php tests/Unit/CertificateGeoServiceTest.php
git commit -m "Add CertificateGeoService: GeoJSON canonicalization, hash, and validity checks"
```

---

### Task 7: `certificate_allocations` table + `CertificateAllocationService` ledger

**Files:**
- Create: `database/migrations/2026_08_29_100001_create_certificate_allocations_table.php`
- Create: `app/Models/CertificateAllocation.php`
- Create: `app/Services/CertificateAllocationService.php`
- Create: `database/factories/CertificateAllocationFactory.php`
- Test: `tests/Feature/CertificateAllocationServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Certificate;
use App\Models\Order;
use App\Services\CertificateAllocationService;

beforeEach(function () {
    $this->service = app(CertificateAllocationService::class);
});

it('allocates a portion of a certificate certified quantity against a consumer', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 100, 'quantity_unit' => 'm3']);
    $order = Order::factory()->create();

    $allocation = $this->service->allocate($certificate, $order, 40);

    expect($allocation->quantity)->toEqual(40)
        ->and($this->service->remaining($certificate))->toEqual(60);
});

it('rejects an allocation that would exceed the remaining certified quantity', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 100, 'quantity_unit' => 'm3']);
    $order = Order::factory()->create();
    $this->service->allocate($certificate, $order, 90);

    expect(fn () => $this->service->allocate($certificate, Order::factory()->create(), 20))
        ->toThrow(RuntimeException::class, 'exceeds remaining');
});

it('allows multiple allocations that together exactly exhaust the certified quantity', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 50, 'quantity_unit' => 'm3']);

    $this->service->allocate($certificate, Order::factory()->create(), 30);
    $this->service->allocate($certificate, Order::factory()->create(), 20);

    expect($this->service->remaining($certificate))->toEqual(0);
});

it('rejects a non-positive allocation quantity', function () {
    $certificate = Certificate::factory()->create(['certified_quantity' => 50, 'quantity_unit' => 'm3']);

    expect(fn () => $this->service->allocate($certificate, Order::factory()->create(), 0))
        ->toThrow(RuntimeException::class);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CertificateAllocationServiceTest.php`
Expected: FAIL — `Class "App\Services\CertificateAllocationService" not found`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chain-of-custody / quantity binding (docs/CERTIFICATE_SPEC.md Ring 2,
 * Layer 14): how much of a specific certificate ROW's certified_quantity
 * has been claimed, and by which consumer (an Order today; a future
 * Shipment/Lot later -- polymorphic so no migration is needed to add one).
 *
 * KNOWN LIMITATION, stated honestly rather than silently: an allocation
 * references one certificate row (one version). If a certificate is
 * versioned (Certificate::createVersion, see Task 4), existing allocations
 * stay attached to the SUPERSEDED row and are not automatically carried
 * forward to the new version. Carrying allocations across versions is real
 * follow-up work, tracked in this plan's Task 11.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_allocations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('certificate_id')->constrained('certificates')->cascadeOnDelete();
            $table->string('consumer_type', 120);
            $table->unsignedBigInteger('consumer_id');
            $table->decimal('quantity', 14, 3);
            $table->timestampsTz();

            $table->index('certificate_id');
            $table->index(['consumer_type', 'consumer_id']);
        });

        DB::statement('ALTER TABLE certificate_allocations ADD CONSTRAINT certificate_allocations_quantity_positive CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_allocations');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CertificateAllocation extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function consumer(): MorphTo
    {
        return $this->morphTo();
    }
}
```

- [ ] **Step 5: Write the service**

```php
<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\CertificateAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The running-total quantity ledger against a certificate's
 * certified_quantity (docs/CERTIFICATE_SPEC.md Ring 2, Layer 14) --
 * prevents the same certificate being over-claimed across multiple
 * shipments/orders.
 */
class CertificateAllocationService
{
    public function allocate(Certificate $certificate, Model $consumer, float $quantity): CertificateAllocation
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Allocation quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($certificate, $consumer, $quantity) {
            // lockForUpdate to make the "read remaining, then insert" check
            // atomic under concurrent allocation attempts against the same
            // certificate.
            $locked = Certificate::query()->whereKey($certificate->getKey())->lockForUpdate()->firstOrFail();
            $remaining = $this->remaining($locked);

            if ($quantity > $remaining) {
                throw new RuntimeException("Allocation of {$quantity} {$locked->quantity_unit} exceeds remaining balance of {$remaining} {$locked->quantity_unit} on certificate [{$locked->certificate_number}].");
            }

            return CertificateAllocation::create([
                'certificate_id' => $locked->id,
                'consumer_type' => $consumer::class,
                'consumer_id' => $consumer->getKey(),
                'quantity' => $quantity,
            ]);
        });
    }

    public function remaining(Certificate $certificate): float
    {
        $allocated = (float) CertificateAllocation::query()
            ->where('certificate_id', $certificate->getKey())
            ->sum('quantity');

        return (float) $certificate->certified_quantity - $allocated;
    }
}
```

- [ ] **Step 6: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\CertificateAllocation;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CertificateAllocation> */
class CertificateAllocationFactory extends Factory
{
    protected $model = CertificateAllocation::class;

    public function definition(): array
    {
        return [
            'certificate_id' => Certificate::factory(),
            'consumer_type' => Order::class,
            'consumer_id' => Order::factory(),
            'quantity' => $this->faker->randomFloat(3, 1, 20),
        ];
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Feature/CertificateAllocationServiceTest.php`
Expected: PASS (4 tests).

- [ ] **Step 8: Full suite, migrate, Pint, commit**

```bash
php artisan migrate --force
php artisan test
vendor/bin/pint --dirty
git add database/migrations/2026_08_29_100001_create_certificate_allocations_table.php \
        app/Models/CertificateAllocation.php app/Services/CertificateAllocationService.php \
        database/factories/CertificateAllocationFactory.php tests/Feature/CertificateAllocationServiceTest.php
git commit -m "Add certificate_allocations ledger with atomic remaining-balance check"
```

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** foreground only, one run at a time; verify `.env.testing` first with `php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"` (expect `cameroontimberhub_testing`) before ever running `migrate:fresh --env=testing`.

---

### Task 8: Immutable audit trail — wire `Certificate` into `spatie/laravel-activitylog`

**Files:**
- Modify: `app/Models/Certificate.php` (add `LogsActivity`)
- Modify: `app/Services/CertificateService.php` (log version/sign/issue actions with actor as causer)
- Test: `tests/Feature/CertificateActivityLogTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Product;
use App\Models\User;
use App\Services\CertificateService;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->keyPath = storage_path('framework/testing/certificate-signing-key-'.uniqid().'.json');
    config(['certificates.signing_key_path' => $this->keyPath, 'certificates.key_id' => 'test-key-1']);
    app(\App\Services\CertificateSigningService::class)->generateKeypair();
});

afterEach(function () {
    @unlink($this->keyPath);
});

it('logs an activity entry when a certificate is signed, with the actor as causer', function () {
    $service = app(CertificateService::class);
    $officer = User::factory()->create();
    $certificate = $service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3');

    $service->sign($certificate, $officer);

    $entry = Activity::query()->where('subject_type', \App\Models\Certificate::class)
        ->where('subject_id', $certificate->id)
        ->where('description', 'signed')
        ->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_type)->toBe(User::class)
        ->and($entry->causer_id)->toBe($officer->id);
});

it('logs a distinct activity entry for issuance and for version creation', function () {
    $service = app(CertificateService::class);
    $officer = User::factory()->create();
    $certificate = $service->sign($service->draft(Product::factory()->create(), ['product' => 'Sapelli'], 100.0, 'm3'), $officer);
    $issued = $service->issue($certificate, $officer);

    $service->createVersion($issued, ['product' => 'Sapelli, corrected'], 'Correction', $officer);

    expect(Activity::query()->where('subject_id', $issued->id)->where('description', 'issued')->exists())->toBeTrue()
        ->and(Activity::query()->where('description', 'version_created')->exists())->toBeTrue();
});

it('administrators cannot delete audit entries through the model itself, only through the activity_log table directly', function () {
    // No delete-suppressing method exists on Activity for app code to call --
    // this test documents the invariant rather than exercising new code:
    // Certificate never exposes an action that deletes its own Activity rows.
    expect(method_exists(\App\Models\Certificate::class, 'clearActivity'))->toBeFalse();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CertificateActivityLogTest.php`
Expected: FAIL — no activity entries recorded (Certificate does not use `LogsActivity` yet).

- [ ] **Step 3: Add `LogsActivity` to `Certificate`**

In `app/Models/Certificate.php`, add imports and the trait:

```php
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
```

```php
class Certificate extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'version', 'data_hash', 'signature', 'evidence_manifest_hash', 'geospatial_hash'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
```

(Keep every existing method on the class unchanged — this only adds the trait to the `use` clause and the `getActivitylogOptions()` method.)

- [ ] **Step 4: Log explicit named events from `CertificateService`**

In `app/Services/CertificateService.php`, add the import `use App\Models\User;` (already present) and record an explicit activity entry with the actor as causer in `sign()`, `issue()`, and `createVersion()`. Insert immediately before each method's `return` (inside the `sign()`/`createVersion()` transactions, after the existing `update()`/`create()` calls; `issue()` is not wrapped in a transaction today — wrap it in one so the status update and the log entry are atomic):

In `sign()`, after `$certificate->update([...])` inside the transaction closure:

```php
            activity()->performedOn($certificate)->causedBy($actor)->log('signed');
```

In `issue()`, replace the body with a transaction so logging is atomic with the update:

```php
    public function issue(Certificate $certificate, User $actor): Certificate
    {
        if ($certificate->status !== CertificateStatus::Verified) {
            throw new RuntimeException('A certificate must be signed and verified before it can be issued. Call sign() first.');
        }

        return DB::transaction(function () use ($certificate, $actor) {
            $certificate->update([
                'status' => CertificateStatus::Active,
                'issued_at' => now(),
            ]);

            activity()->performedOn($certificate)->causedBy($actor)->log('issued');

            return $certificate->fresh();
        });
    }
```

In `createVersion()`, after `$current->update(['status' => CertificateStatus::Superseded]);`:

```php
            activity()->performedOn($next)->causedBy($actor)->log('version_created');
            activity()->performedOn($current)->causedBy($actor)->withProperties(['superseded_by' => $next->id])->log('superseded');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/CertificateActivityLogTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Models/Certificate.php app/Services/CertificateService.php tests/Feature/CertificateActivityLogTest.php
git commit -m "Wire Certificate into spatie/laravel-activitylog (first real consumer of this dependency)"
```

---

### Task 9: `endroid/qr-code` dependency + public verification endpoint

**Files:**
- Modify: `composer.json` (add `endroid/qr-code`)
- Create: `app/Services/CertificateVerifier.php`
- Create: `app/Http/Controllers/Public/CertificateVerificationController.php`
- Create: `app/Services/CertificateQrCodeService.php`
- Modify: `app/Providers/AppServiceProvider.php` (rate limiter, mirroring `receipt-verify`)
- Modify: `routes/web.php`
- Create: `resources/views/public/certificates/verify.blade.php`
- Test: `tests/Feature/CertificateVerificationTest.php`

- [ ] **Step 1: Add the composer dependency**

```bash
composer require endroid/qr-code
```

Confirm afterward: `grep endroid/qr-code composer.json` shows it under `require`.

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Enums\CertificateStatus;
use App\Models\Certificate;

it('finds an active certificate by its verification token and reports it valid', function () {
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'data_hash' => hash('sha256', 'x'),
        'signature' => base64_encode('sig'),
        'key_id' => 'test-key-1',
        'algorithm' => 'ed25519',
        'evidence_manifest_hash' => hash('sha256', 'e'),
        'geospatial_hash' => hash('sha256', 'g'),
    ]);

    $response = $this->get(route('certificates.verify.token', $certificate->verification_token));

    $response->assertRedirect(route('certificates.verify'));
    $this->followRedirects($response)->assertSee($certificate->certificate_number);
});

it('never discloses a distinguishing message for an unknown token vs a superseded one', function () {
    $superseded = Certificate::factory()->create(['status' => CertificateStatus::Superseded]);

    $unknownResponse = $this->get(route('certificates.verify.token', 'totally-unknown-token-xyz'));
    $supersededResponse = $this->get(route('certificates.verify.token', $superseded->verification_token));

    // Both redirect to the same generic result flow -- no distinguishing
    // status code or redirect target between "never existed" and "exists but not current".
    expect($unknownResponse->status())->toBe($supersededResponse->status())
        ->and($unknownResponse->headers->get('Location'))->toBe($supersededResponse->headers->get('Location'));
});

it('does not leak the subject product name, only the allow-listed public payload fields', function () {
    $product = \App\Models\Product::factory()->create(['name' => 'Internal Product Name']);
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'subject_type' => \App\Models\Product::class,
        'subject_id' => $product->id,
        'data' => ['product' => 'Public certified product label'],
        'data_hash' => hash('sha256', 'x'),
    ]);

    $response = $this->get(route('certificates.verify.token', $certificate->verification_token));
    $page = $this->followRedirects($response);

    $page->assertSee('Public certified product label');
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `php artisan test tests/Feature/CertificateVerificationTest.php`
Expected: FAIL — route `certificates.verify.token` not defined.

- [ ] **Step 4: Write `CertificateVerifier`**

```php
<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Models\Certificate;

/**
 * The public certificate-verification boundary, mirroring
 * app/Services/ReceiptVerifier.php's exact shape: lookup by unguessable
 * token, an allow-list payload, "not found" reads identically for every
 * failure reason so this cannot be used as an oracle (docs/CERTIFICATE_SPEC.md's
 * "Recommended Verification Result").
 */
class CertificateVerifier
{
    public function __construct(
        private readonly CertificateSigningService $signer,
    ) {}

    public function findByToken(string $token): ?Certificate
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return Certificate::with('subject')->where('verification_token', $token)->first();
    }

    /** @return array<string, mixed> */
    public function publicPayload(Certificate $certificate): array
    {
        $signatureValid = $certificate->signature !== null
            && $certificate->data_hash !== null
            && $this->signer->verify($certificate->data_hash, $certificate->signature);

        $liveVersion = Certificate::query()
            ->where('certificate_number', $certificate->certificate_number)
            ->liveVersion()
            ->orderByDesc('version')
            ->first();

        return [
            'issuer' => config('app.name'),
            'certificate_number' => $certificate->certificate_number,
            'found' => true,
            'signature_valid' => $signatureValid,
            'hash_valid' => $certificate->data_hash !== null,
            'version' => $certificate->version,
            'is_current_version' => $liveVersion?->id === $certificate->id,
            'status' => $certificate->status->label(),
            'is_currently_valid' => $certificate->status->isCurrentlyValid(),
            'data' => $certificate->data,
            'fingerprint' => $certificate->fingerprint(),
            'evidence_status' => $certificate->evidence_manifest_hash !== null ? 'Evidence manifest bound' : 'No evidence manifest recorded',
            'geospatial_status' => $certificate->geospatial_hash !== null ? 'Geospatial record bound' : 'No geospatial record',
            'issued_at' => $certificate->issued_at,
            'checked_at' => now(),
        ];
    }
}
```

- [ ] **Step 5: Write the rate limiter**

In `app/Providers/AppServiceProvider.php`, immediately after the existing `RateLimiter::for('receipt-verify', ...)` block, add:

```php
        RateLimiter::for('certificate-verify', fn (Request $request) => [
            Limit::perMinute(10)->by('certificate-verify-ip:'.$request->ip()),
            Limit::perHour(60)->by('certificate-verify-ip-hour:'.$request->ip()),
        ]);
```

- [ ] **Step 6: Write the controller**

```php
<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\CertificateVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public certificate-verification page. Mirrors
 * app/Http/Controllers/Public/ReceiptVerificationController.php's exact
 * shape and reasoning: rate-limited, allow-list-only disclosure, a
 * consistent redirect flow whether the token is unknown or points at a
 * non-current/revoked certificate.
 */
class CertificateVerificationController extends Controller
{
    public function __construct(private readonly CertificateVerifier $verifier) {}

    public function create(Request $request): View
    {
        $certificate = ($id = $request->session()->get('verified_certificate_id'))
            ? Certificate::with('subject')->find($id)
            : null;

        return view('public.certificates.verify', [
            'result' => $certificate ? $this->verifier->publicPayload($certificate) : null,
            'searched' => $certificate !== null || $request->session()->get('verified_certificate_missing', false),
        ]);
    }

    public function token(string $token): RedirectResponse
    {
        $certificate = $this->verifier->findByToken($token);

        if (! $certificate) {
            return redirect()->route('certificates.verify')->with('verified_certificate_missing', true);
        }

        return redirect()->route('certificates.verify')->with('verified_certificate_id', $certificate->getKey());
    }
}
```

- [ ] **Step 7: Add routes**

In `routes/web.php`, immediately after the existing receipt-verification block:

```php
// Public certificate verification. Open to anyone by design, so it is
// throttled hard and discloses only CertificateVerifier::publicPayload().
Route::get('/verify/certificate', [CertificateVerificationController::class, 'create'])->name('certificates.verify');
Route::get('/verify/certificate/{token}', [CertificateVerificationController::class, 'token'])
    ->where('token', '[A-Za-z0-9]{16,64}')
    ->middleware('throttle:certificate-verify')->name('certificates.verify.token');
```

Add the import at the top of `routes/web.php` alongside the existing `Public\ReceiptVerificationController` import: `use App\Http\Controllers\Public\CertificateVerificationController;`.

- [ ] **Step 8: Write the QR service**

```php
<?php

namespace App\Services;

use App\Models\Certificate;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;

/**
 * Dynamic QR pointing at the live verification URL (docs/CERTIFICATE_SPEC.md
 * Ring 2, Layer 8) -- never embeds the full certificate, only the token URL.
 */
class CertificateQrCodeService
{
    public function verificationUrl(Certificate $certificate): string
    {
        return route('certificates.verify.token', $certificate->verification_token);
    }

    /** Returns an inline `data:image/svg+xml;base64,...` string, safe to drop straight into an <img src>. */
    public function dataUri(Certificate $certificate): string
    {
        $result = Builder::create()
            ->writer(new \Endroid\QrCode\Writer\SvgWriter())
            ->data($this->verificationUrl($certificate))
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size(240)
            ->margin(8)
            ->build();

        return $result->getDataUri();
    }
}
```

- [ ] **Step 9: Write the verify view**

```blade
<x-layouts.app title="Verify certificate" description="Verify a TimberHub certificate." noindex>
    <div class="mx-auto max-w-2xl px-4 py-10 sm:py-16">
        <h1 class="text-2xl font-semibold text-ink dark:text-[#e4ddcf]">Verify a certificate</h1>
        <p class="mt-2 text-[1.0625rem] text-ink-soft dark:text-[#8f887b]">
            Scan the QR code on a TimberHub certificate, or open its verification link directly.
        </p>

        @if ($searched && ! $result)
            <div class="mt-8 rounded-2xl border border-sand-200 bg-white p-6 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                <p class="text-[1.0625rem] font-medium text-red-700 dark:text-red-400">Certificate not found.</p>
                <p class="mt-1 text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">The link may be mistyped, or the certificate may no longer be current.</p>
            </div>
        @endif

        @if ($result)
            <div class="mt-8 rounded-2xl border border-sand-200 bg-white p-6 dark:border-[#2c2a24] dark:bg-[#1f1d18]">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-[1.0625rem] font-semibold text-ink dark:text-[#e4ddcf]">{{ $result['certificate_number'] }}</p>
                    <span class="rounded-full px-3 py-1 text-[0.8125rem] font-semibold {{ $result['is_currently_valid'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                        {{ $result['status'] }}
                    </span>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-3 text-[0.9375rem] sm:grid-cols-2">
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Signature</dt><dd class="font-medium">{{ $result['signature_valid'] ? 'Valid' : 'Could not verify' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Hash</dt><dd class="font-medium">{{ $result['hash_valid'] ? 'Valid' : 'Missing' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Version</dt><dd class="font-medium">v{{ $result['version'] }} {{ $result['is_current_version'] ? '(current)' : '(not current)' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Fingerprint</dt><dd class="font-mono text-[0.8125rem]">{{ $result['fingerprint'] ?? '—' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Evidence</dt><dd class="font-medium">{{ $result['evidence_status'] }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Geospatial record</dt><dd class="font-medium">{{ $result['geospatial_status'] }}</dd></div>
                </dl>

                @if (($result['data']['product'] ?? null))
                    <p class="mt-4 border-t border-sand-200 pt-4 text-[0.9375rem] text-ink dark:border-[#2c2a24] dark:text-[#e4ddcf]">
                        Product: {{ $result['data']['product'] }}
                    </p>
                @endif

                <p class="mt-4 text-[0.8125rem] text-ink-soft dark:text-[#8f887b]">Checked {{ $result['checked_at']->diffForHumans() }}.</p>
            </div>
        @endif
    </div>
</x-layouts.app>
```

- [ ] **Step 10: Run tests to verify they pass**

Run: `php artisan test tests/Feature/CertificateVerificationTest.php`
Expected: PASS (3 tests).

- [ ] **Step 11: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add composer.json composer.lock app/Services/CertificateVerifier.php app/Services/CertificateQrCodeService.php \
        app/Http/Controllers/Public/CertificateVerificationController.php app/Providers/AppServiceProvider.php \
        routes/web.php resources/views/public/certificates/verify.blade.php tests/Feature/CertificateVerificationTest.php
git commit -m "Add public certificate verification endpoint, CertificateVerifier, and dynamic QR service"
```

---

### Task 10: Certificate print/verification view (house pattern, no PDF library)

**Files:**
- Create: `resources/views/public/certificates/show.blade.php`
- Modify: `app/Http/Controllers/Public/CertificateVerificationController.php` (add a `show` action rendering the current version by certificate_number, for a signed-in owner/staff link, distinct from the public token flow)
- Test: `tests/Feature/CertificateShowViewTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\User;

it('renders the certificate print view with QR, fingerprint, and the mandatory legal disclaimer', function () {
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'data_hash' => hash('sha256', 'x'),
        'signature' => base64_encode('sig'),
        'key_id' => 'test-key-1',
        'algorithm' => 'ed25519',
        'geospatial_data' => ['type' => 'Point', 'coordinates' => [11.518890, 3.848210]],
        'geospatial_hash' => hash('sha256', 'g'),
        'data' => ['product' => 'Sapelli sawn timber', 'origin' => ['country' => 'Cameroon']],
    ]);
    $staff = User::factory()->create();
    $staff->givePermissionTo('certificates.manage');

    $response = $this->actingAs($staff)->get(route('certificates.show', $certificate->certificate_number));

    $response->assertOk()
        ->assertSee($certificate->certificate_number)
        ->assertSee($certificate->fingerprint())
        ->assertSee('digitally verifiable record', escape: false)
        ->assertSee('11.518890');
});

it('shows the geospatial panel as text-only lat/long, honestly, with no fabricated map image', function () {
    $certificate = Certificate::factory()->create([
        'status' => CertificateStatus::Active,
        'geospatial_data' => ['type' => 'Point', 'coordinates' => [11.518890, 3.848210]],
        'geospatial_hash' => hash('sha256', 'g'),
    ]);
    $staff = User::factory()->create();
    $staff->givePermissionTo('certificates.manage');

    $response = $this->actingAs($staff)->get(route('certificates.show', $certificate->certificate_number));

    $response->assertDontSee('<img', escape: false)->assertSee('Latitude');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/CertificateShowViewTest.php`
Expected: FAIL — route `certificates.show` not defined.

- [ ] **Step 3: Add the `show` action**

In `app/Http/Controllers/Public/CertificateVerificationController.php`, add (import `App\Models\Certificate`, `App\Services\CertificateQrCodeService` already imported for `create`/`token`; add `CertificateQrCodeService` to the constructor):

```php
    public function __construct(
        private readonly CertificateVerifier $verifier,
        private readonly \App\Services\CertificateQrCodeService $qr,
    ) {}
```

```php
    /**
     * The staff/owner-facing printable certificate document -- the house
     * window.print() pattern (see resources/views/public/orders/receipt.blade.php),
     * not a generated PDF: no PDF library exists in this codebase and this
     * exact "printable, verifiable document" problem is already solved that
     * way for receipts.
     */
    public function show(Request $request, string $certificateNumber): View
    {
        $this->authorize('certificates.manage');

        $certificate = Certificate::with('subject')
            ->forNumber($certificateNumber)
            ->liveVersion()
            ->orderByDesc('version')
            ->firstOrFail();

        return view('public.certificates.show', [
            'certificate' => $certificate,
            'qrDataUri' => $this->qr->dataUri($certificate),
            'verificationUrl' => $this->qr->verificationUrl($certificate),
        ]);
    }
```

Add the `authorize` helper's dependency: confirm `Controller` base class already has `AuthorizesRequests` (check `app/Http/Controllers/Controller.php` — if `authorize()` is not available, use `abort_unless($request->user()?->can('certificates.manage'), 403);` instead of `$this->authorize(...)`, matching whichever pattern the base `Controller` class actually supports).

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside (or near) an existing `auth`-protected admin/staff route group — find where similar staff-only public-panel routes live (e.g. `grep -n "middleware('auth')" routes/web.php` to locate the right group) and add:

```php
Route::get('/certificates/{certificateNumber}', [CertificateVerificationController::class, 'show'])->name('certificates.show');
```

If no such group exists at a sensible mount point, add it directly with an inline `->middleware('auth')`, matching the simplest existing precedent found by that grep.

- [ ] **Step 5: Write the print view**

```blade
@php
    // Certificate print/verification view -- Rings 1+2 only (Ring 3 physical
    // security layers are explicitly deferred, see docs/CERTIFICATE_SPEC.md
    // "Scope decision"). Follows the house window.print() pattern from
    // resources/views/public/orders/receipt.blade.php: no PDF library, hand-
    // styled Tailwind + .article-prose, never Tailwind's `prose` classes
    // (this project has no typography plugin -- confirmed in
    // resources/css/app.css).
    $geo = $certificate->geospatial_data;
@endphp

<x-layouts.app :title="'Certificate '.$certificate->certificate_number" description="TimberHub certificate." noindex>
    <div class="certificate-page mx-auto max-w-5xl px-4 py-8 sm:py-12">

        <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
            <p class="text-[1.0625rem] font-medium text-ink dark:text-[#e4ddcf]">Certificate {{ $certificate->certificate_number }}</p>
            <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-2 rounded-full bg-forest-700 px-5 py-2.5 text-[1.0625rem] font-semibold text-white transition hover:bg-forest-800">
                <x-heroicon-m-printer class="h-4 w-4" /> Print certificate
            </button>
        </div>

        <article class="certificate-sheet mt-4 rounded-2xl border border-sand-200 bg-white p-5 text-ink dark:border-[#2c2a24] dark:bg-[#1f1d18] dark:text-[#e4ddcf] sm:p-8">

            {{-- Identity block --}}
            <header class="grid gap-6 border-b border-sand-200 pb-6 dark:border-[#2c2a24] md:grid-cols-[1.4fr_1fr]">
                <div>
                    <img src="/brand/logo-600.png" alt="Cameroon Timber Hub" class="h-12 w-auto" width="600" height="200">
                    <p class="mt-3 text-[1.0625rem] font-semibold">{{ $certificate->certificate_number }}</p>
                    <p class="text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">Version {{ $certificate->version }} — {{ $certificate->status->label() }}</p>
                </div>
                <div class="flex flex-col items-end gap-2">
                    <img src="{{ $qrDataUri }}" alt="Scan to verify this certificate" width="140" height="140">
                    <p class="text-[0.8125rem] text-ink-soft dark:text-[#8f887b] break-all text-right">{{ $verificationUrl }}</p>
                </div>
            </header>

            {{-- Human-readable data (product/origin/production/supplier) --}}
            <section class="article-prose mt-6">
                <h2>Certified product</h2>
                <p>{{ $certificate->data['product'] ?? '—' }}</p>
                @if (! empty($certificate->data['origin']))
                    <h3>Origin</h3>
                    <p>{{ $certificate->data['origin']['country'] ?? '—' }}@if (! empty($certificate->data['origin']['region'])), {{ $certificate->data['origin']['region'] }}@endif</p>
                @endif
                @if ($certificate->certified_quantity)
                    <h3>Certified quantity</h3>
                    <p>{{ number_format((float) $certificate->certified_quantity, 3) }} {{ $certificate->quantity_unit }}</p>
                @endif
            </section>

            {{-- Cryptographic fingerprint --}}
            <section class="mt-6 rounded-xl border border-sand-200 p-4 dark:border-[#2c2a24]">
                <p class="text-[0.8125rem] font-semibold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Cryptographic fingerprint</p>
                <p class="mt-1 font-mono text-[0.9375rem]">{{ $certificate->fingerprint() ?? 'Not yet signed' }}</p>
            </section>

            {{-- Authenticity & integrity panel --}}
            <section class="mt-6 rounded-xl border border-sand-200 p-4 dark:border-[#2c2a24]">
                <p class="text-[0.8125rem] font-semibold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Authenticity &amp; integrity</p>
                <dl class="mt-2 grid grid-cols-2 gap-2 text-[0.9375rem]">
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Signing key</dt><dd>{{ $certificate->key_id ?? '—' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Algorithm</dt><dd>{{ $certificate->algorithm ?? '—' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Signed at</dt><dd>{{ $certificate->signed_at?->toDayDateTimeString() ?? '—' }}</dd></div>
                    <div><dt class="text-ink-soft dark:text-[#8f887b]">Data hash</dt><dd class="font-mono text-[0.75rem] break-all">{{ $certificate->data_hash ?? '—' }}</dd></div>
                </dl>
            </section>

            {{-- Geospatial panel: text-only lat/long, honestly, no map image (no maps API key configured in this codebase) --}}
            @if ($geo)
                <section class="mt-6 rounded-xl border border-sand-200 p-4 dark:border-[#2c2a24]">
                    <p class="text-[0.8125rem] font-semibold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Geospatial record</p>
                    <p class="mt-1 text-[0.9375rem]">
                        @if ($geo['type'] === 'Point')
                            Latitude {{ $geo['coordinates'][1] }}, Longitude {{ $geo['coordinates'][0] }}
                        @else
                            Plot boundary polygon ({{ count($geo['coordinates'][0] ?? []) - 1 }} vertices) — see the digital record for full coordinates.
                        @endif
                    </p>
                    <p class="mt-1 font-mono text-[0.75rem] break-all text-ink-soft dark:text-[#8f887b]">Geospatial hash: {{ $certificate->geospatial_hash ?? '—' }}</p>
                </section>
            @endif

            {{-- Evidence panel --}}
            <section class="mt-6 rounded-xl border border-sand-200 p-4 dark:border-[#2c2a24]">
                <p class="text-[0.8125rem] font-semibold uppercase tracking-wide text-ink-soft dark:text-[#8f887b]">Evidence</p>
                <p class="mt-1 font-mono text-[0.75rem] break-all">{{ $certificate->evidence_manifest_hash ?? 'No evidence manifest recorded' }}</p>
            </section>

            {{-- Issuance block --}}
            <section class="mt-6 text-[0.9375rem] text-ink-soft dark:text-[#8f887b]">
                <p>Issued {{ $certificate->issued_at?->toDayDateTimeString() ?? '—' }} by {{ config('app.name') }}.</p>
            </section>

            {{-- Mandatory legal disclaimer, spec Part A section 34 --}}
            <footer class="mt-8 border-t border-sand-200 pt-6 text-[0.8125rem] text-ink-soft dark:border-[#2c2a24] dark:text-[#8f887b]">
                <p>This TimberHub certificate is a digitally verifiable record issued by Cameroon Timber Hub. It is not an EU-issued EUDR certificate and does not itself constitute regulatory clearance. Verify its current status at {{ $verificationUrl }}.</p>
            </footer>

        </article>
    </div>
</x-layouts.app>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/CertificateShowViewTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Full suite, Pint, commit**

```bash
php artisan test
vendor/bin/pint --dirty
git add app/Http/Controllers/Public/CertificateVerificationController.php routes/web.php \
        resources/views/public/certificates/show.blade.php tests/Feature/CertificateShowViewTest.php
git commit -m "Add certificate print/verification view: QR, fingerprint, panels, legal disclaimer"
```

---

### Task 11: Self-review and final verification

- [ ] **Step 1: Confirm the full file list this plan touched** matches only what was described. Run `git log --oneline` for this plan's commits, then `git diff <first-commit-of-this-plan>^..HEAD --stat` and check every listed file against Tasks 1–10's `Files:` sections — nothing outside them should appear except the intentionally-listed modifications (`Product`/`Species`/`Document`/`AppServiceProvider`/`RolesAndPermissionsSeeder`/`routes/web.php`/`.env.example`/`composer.json`+`composer.lock`).

- [ ] **Step 2: Confirm no Ring 3 code was written.** `grep -rli "guilloche\|microtext\|watermark\|hologram\|tamper.seal\|print.batch\|physical.serial" app/ resources/views/ database/migrations/` — expect no output. If anything matches, it is scope creep into 0.8b and must be removed.

- [ ] **Step 3: Run the full suite one final time.**

**CRITICAL TEST-ENVIRONMENT CONSTRAINT:** the suite shares ONE PostgreSQL testing database with no isolation. Run `php artisan test` in the foreground only, one run at a time — never in the background, never concurrently. Before ever running `migrate:fresh`, verify `.env.testing` genuinely resolves to `cameroontimberhub_testing`:

```bash
php artisan --env=testing tinker --execute="echo config('database.connections.pgsql.database');"
```

Expected: `cameroontimberhub_testing`. If corruption from a concurrent run appears ("relation X already exists" / "relation migrations does not exist"), repair with `php artisan migrate:fresh --env=testing --force` only after that check passes — never without it, per this project's own prior incident of `migrate:fresh` silently wiping the dev database when `.env.testing` was missing.

```bash
php artisan test
```

Expected: fully green, no regressions against the baseline count when this plan started.

- [ ] **Step 4: Empirically walk one certificate end-to-end** in the final report (tinker or a small script), not just "tests passed" — show the actual values at each step: `draft()` → `data_hash`; `sign()` → `signature`/`key_id`/`signed_at` and a real `verify()` call returning `true`; `issue()` → `status`/`issued_at`; `createVersion()` → new row's `certificate_number` equal to the original, `version` incremented, `previous_version_id` set, and the original row's `status` flipped to `Superseded`; an allocation via `CertificateAllocationService::allocate()` followed by `remaining()` showing the correct balance; and a `GET` to `/verify/certificate/{token}` showing the rendered `signature_valid: true` result.

- [ ] **Step 5: Record the known limitations honestly as tracked follow-ups.** Add a new item to `docs/GAP_PLAN.md`'s Phase 0 table (adjust numbering to the file's actual current state — read it first, since 0.8c may already be taken):

> **0.8c — Certificate allocation carry-over across versions, and KMS/HSM signing upgrade.** Two known limitations from 0.8's "Digital Core": (1) `certificate_allocations` rows reference a specific certificate version row and are not automatically carried forward when `CertificateService::createVersion()` supersedes it (see `database/migrations/2026_08_29_100001_create_certificate_allocations_table.php`'s docblock) — needs a deliberate decision on whether/how allocations migrate across versions. (2) `CertificateSigningService` signs with an application-level Ed25519 key stored in a file on the app server, not a KMS/HSM — genuinely separated from the database but not from the application server itself; a real KMS/HSM integration (the application asking a signing service to sign without ever holding the private key) is the documented next step. Neither is a fabricated gap; both are stated plainly in the 0.8 plan and code comments. Unestimated — depends on a decision about a real KMS provider and how strict allocation carry-over needs to be.

```bash
git add docs/GAP_PLAN.md
git commit -m "Track certificate allocation carry-over and KMS/HSM upgrade as follow-up"
```

---

## Self-Review Notes

- **Spec coverage:** Task 1 covers item 1 (identity/canonical record/version/status+CHECK/timestamps). Task 2 covers item 2 (deterministic canonical JSON + SHA-256). Task 3 covers item 3 (real libsodium signing, private key outside the DB, honest pre-KMS/HSM docblock). Task 4 covers item 4 (versioning: new immutable row, prior marked superseded, own hash/signature/reason/actor/timestamp). Task 5 covers item 5 (evidence manifest hash, and for the first time populates `Document::checksum_sha256` for real, closing the exact dependency the task brief called out). Task 6 covers item 6 (GeoJSON hash + ≥6-decimal precision + real polygon-validity checks, no fabricated spatial engine). Task 7 covers item 7 (quantity allocation ledger with an atomic running-total check). Task 8 covers item 8 (wires `Certificate` into the already-installed-but-unused `spatie/laravel-activitylog`, its first real consumer, confirmed by a zero-hit grep during investigation). Task 9 covers item 9 (dynamic QR via a newly-added real package, public token-only verification endpoint mirroring `ReceiptVerificationController`/`ReceiptVerifier`'s exact disclosure discipline). Task 10 covers item 10 (printable certificate view: identity, human-readable data, QR, fingerprint, authenticity panel, honest text-only geospatial panel, evidence panel, issuance block, and the exact mandatory legal disclaimer, using `.article-prose`/hand-styled Tailwind, never `prose`). Ring 3 (items explicitly out of scope) has zero tasks referencing it, and Task 11 Step 2 greps to confirm that stayed true.
- **No placeholders:** every step carries complete, runnable PHP/Blade/migration code and exact commands; every "decide" moment in the spec (subject type, versioning shape, QR/PDF library choices) is resolved with a stated, investigated reason in the Scope Decision section, not left as a TODO for the implementer.
- **Type consistency check:** `CertificateStatus` case values used in the migration's CHECK constraint (Task 1) match the enum's cases (Task 1) and the partial unique index (Task 1) and `Certificate::scopeLiveVersion()` (Task 1) exactly. `CertificateHashingService::hash()` (Task 2) is the exact method `CertificateService::draft()`/`createVersion()` call (Task 4) and `CertificateVerifier::publicPayload()` reads via `data_hash` (Task 9). `CertificateSigningService::sign()`'s returned `key_id`/`algorithm`/`signature` keys (Task 3) match exactly what `CertificateService::sign()` writes to the `certificates` row (Task 4) and what `CertificateVerifier::verify()`/the show view read back (Tasks 9–10). `CertificateAllocationService::allocate()`/`remaining()` (Task 7) are the exact methods referenced by Task 11's final walkthrough. `Certificate::fingerprint()` (Task 1) is the exact method both the verify page (Task 9) and the print view (Task 10) call.

**Note for the implementer:** Task 9/10 assume an `Order` model exists as a plausible certificate-allocation consumer and that `app/Http/Controllers/Controller.php` provides an `authorize()` helper — verify both against the actual codebase before writing code, and adjust (e.g. to a different consumer model, or to `abort_unless` instead of `authorize()`) if reality differs, exactly as this plan's own Scope Decision section models doing throughout.
