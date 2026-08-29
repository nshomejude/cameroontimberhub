# Transport RFQ + Shipment (gap-plan 1.5.10) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Transport RFQ intake + booking → `Shipment` model with a digital waybill whose QR code links the shipment's cargo product IDs, without duplicating fleet or checkpoint-tracking concerns being built concurrently.

**Architecture:** Add `RfqType::Transport` (additive case) and a `createTransport` entry point mirroring `RfqController::createManufacturing`, reusing the existing wizard/steps unchanged. Add a new `Shipment` model/migration belonging to `Order` (the booking), with **nullable, generically-named** `vehicle_id`/`driver_id` integer columns (no FK constraint yet — 1.5.12 hasn't landed) and a `waybill_number`. A `ShipmentService::createFromOrder()` builds the Shipment; a `ShipmentWaybillQrCodeService` (mirrors `CertificateQrCodeService`, reusing `endroid/qr-code`) generates a QR pointing at a public waybill URL that lists the order's cargo product IDs. A `ShipmentWaybillController` renders that public page. No status/location/tracking logic — that is explicitly 1.5.11's job.

**Tech Stack:** Laravel 13, PHP 8.3, PostgreSQL, `endroid/qr-code` (already in composer.json), Pest/PHPUnit feature tests.

---

## Scope Decision

- **Nullable FKs to fleet (1.5.12):** `vehicle_id` and `driver_id` on `shipments` are plain nullable `unsignedBigInteger` columns, **not** foreign keys, with a migration comment stating they become real FKs once 1.5.12's `vehicles`/`drivers` tables land. Confirmed via `ls app/Models` and `ls database/migrations` that neither table exists yet at plan time. A Shipment must be fully creatable/functional with both null.
- **No tracking logic:** `Shipment` carries no status/location/checkpoint fields. The generic `CheckpointUpdate` model (1.5.11, polymorphic `owner_type`/`owner_id`) will attach to `Shipment` later as one of its trackable subjects. This plan does not create or reference `CheckpointUpdate`.
- **Waybill = QR linking cargo product IDs:** the QR encodes a signed-less public URL (`shipments.waybill.show`) keyed by `waybill_number` (a random unguessable token, not the auto-increment id) that renders the shipment's order's line items' product IDs. This mirrors `CertificateQrCodeService`'s "QR points at a live URL" pattern, not an embedded data blob.
- **Entry point:** `/request-quote/transport` mirrors `/request-quote/manufacturing` exactly — same wizard, same steps, only the session-tagged `type` differs. No new wizard step, no new validation rules.
- **Booking → Shipment:** a `Shipment` is created from an `Order` (the existing "booking" concept — an accepted quote already becomes an `Order`). `ShipmentService::createFromOrder(Order $order, array $data = [])` is the one write path, called manually today (no automatic trigger is specified — a controller/action can call it later; this plan proves the service + model + waybill machinery function correctly against a test Order).

## File Structure

- Modify: `app/Enums/RfqType.php` — add `Transport = 'transport'` case + label.
- Modify: `app/Http/Controllers/Public/RfqController.php` — add `createTransport()` mirroring `createManufacturing()`.
- Modify: `routes/web.php` — add `/request-quote/transport` GET route.
- Create: `database/migrations/2026_08_29_100040_create_shipments_table.php`
- Create: `app/Models/Shipment.php`
- Create: `database/factories/ShipmentFactory.php`
- Create: `app/Services/ShipmentService.php`
- Create: `app/Services/ShipmentWaybillQrCodeService.php`
- Create: `app/Http/Controllers/Public/ShipmentWaybillController.php`
- Create: `resources/views/shipments/waybill.blade.php`
- Modify: `routes/web.php` — add public `shipments/{shipment:waybill_number}/waybill` route.
- Test: `tests/Feature/TransportRfqTest.php`
- Test: `tests/Feature/ShipmentWaybillTest.php`

---

## Task 1: `RfqType::Transport` case + label

**Files:**
- Modify: `app/Enums/RfqType.php`

- [ ] **Step 1:** Read the file fresh immediately before editing (shared hotspot — other agents may have added cases).

- [ ] **Step 2:** Add the case additively:

```php
enum RfqType: string
{
    case Export = 'export';
    case DomesticManufacturing = 'domestic_manufacturing';
    case Transport = 'transport';

    public function label(): string
    {
        return match ($this) {
            self::Export => 'Export',
            self::DomesticManufacturing => 'Manufacturing / Local Procurement',
            self::Transport => 'Transport',
        };
    }
}
```

Do not remove or reorder the existing cases. If a concurrent agent has already added other cases, keep them and add `Transport` alongside.

- [ ] **Step 3:** Commit.

```bash
vendor/bin/pint --dirty
git add app/Enums/RfqType.php
git commit -m "Add RfqType::Transport case (gap-plan 1.5.10)"
```

## Task 2: Transport RFQ entry point (failing test first)

**Files:**
- Modify: `app/Http/Controllers/Public/RfqController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TransportRfqTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\RfqType;

test('the transport entry point tags the wizard session with RfqType::Transport', function () {
    $response = $this->get('/request-quote/transport');

    $response->assertOk();
    expect(session('rfq_wizard')['type'])->toBe(RfqType::Transport->value);
});

test('the transport entry point renders the same details step view as export', function () {
    $response = $this->get('/request-quote/transport');

    $response->assertViewIs('rfq.wizard.details');
});
```

- [ ] **Step 2: Confirm the export wizard's actual view name first**

```bash
grep -n "return view" app/Http/Controllers/Public/RfqController.php
```

Use whatever view name that grep reveals in place of `rfq.wizard.details` above if it differs.

- [ ] **Step 3: Run it to verify it fails**

Acquire the test lock first (see "Shared worktree coordination" below), then:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/TransportRfqTest.php
```

Expected: FAIL (404 — route doesn't exist yet).

- [ ] **Step 4: Add the route**

In `routes/web.php`, right after the existing manufacturing route:

```php
Route::get('/request-quote/manufacturing', [RfqController::class, 'createManufacturing'])->name('rfq.create.manufacturing');
Route::get('/request-quote/transport', [RfqController::class, 'createTransport'])->name('rfq.create.transport');
```

- [ ] **Step 5: Add the controller action**

In `app/Http/Controllers/Public/RfqController.php`, right after `createManufacturing()`:

```php
/**
 * The transport RFQ entry point (gap-plan 1.5.10). Same wizard, same
 * steps as export/manufacturing — only the `type` tag stamped into the
 * session before it starts differs, which rides through to `rfqs.type`
 * on submit. Booking a resulting Order into a Shipment is a separate,
 * later step (ShipmentService), not part of the wizard itself.
 */
public function createTransport(Request $request, RfqWizard $wizard, RfqList $list): View|RedirectResponse
{
    $isFirstVisit = $wizard->all() === [];

    $this->seed($request, $wizard, $list);

    if ($isFirstVisit) {
        $wizard->putType(RfqType::Transport);
    }

    return $this->render($request, $wizard, $list, 'details');
}
```

- [ ] **Step 6: Run the test again to verify it passes**

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/TransportRfqTest.php
```

Expected: PASS (2/2).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/Public/RfqController.php routes/web.php tests/Feature/TransportRfqTest.php
git commit -m "Add transport RFQ entry point mirroring the manufacturing one (gap-plan 1.5.10)"
```

## Task 3: `shipments` migration + `Shipment` model + factory

**Files:**
- Create: `database/migrations/2026_08_29_100040_create_shipments_table.php`
- Create: `app/Models/Shipment.php`
- Create: `database/factories/ShipmentFactory.php`
- Test: `tests/Feature/ShipmentWaybillTest.php` (model-creation assertions land here too)

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transport booking turned into a trackable shipment with a digital
 * waybill (gap-plan 1.5.10). Belongs to the Order that is the booking.
 *
 * `vehicle_id`/`driver_id` are deliberately plain nullable integers, NOT
 * foreign keys: gap-plan 1.5.12 (fleet/driver registry) is being built
 * concurrently and its `vehicles`/`drivers` tables do not exist yet. A
 * Shipment must be creatable and fully functional with both null — once
 * 1.5.12 lands, a follow-up migration adds the real FK constraints.
 *
 * No status/location/checkpoint columns here on purpose: gap-plan 1.5.11
 * (manual checkpoint tracking) is a separate, generic, polymorphic
 * `CheckpointUpdate` model (owner_type/owner_id) that will attach to a
 * Shipment as one of its trackable subjects later. This table's only job
 * is to exist as a bookable, waybill-bearing subject for that to point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Unguessable public token used in the waybill URL/QR — never the
            // auto-increment id, so the waybill URL can't be enumerated.
            $table->string('waybill_number', 40)->unique();

            // Fleet linkage (gap-plan 1.5.12), intentionally not yet a real FK — see class doc above.
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();

            $table->string('origin', 200)->nullable();
            $table->string('destination', 200)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transport booking (gap-plan 1.5.10). Owns a digital waybill; carries no
 * status/location fields — checkpoint tracking (1.5.11) is a separate,
 * polymorphic `CheckpointUpdate` model that attaches to this later.
 *
 * `vehicle_id`/`driver_id` are optional and unconstrained (see migration
 * doc) — always guard with `?->` / null checks, never assume a fleet row
 * exists.
 */
class Shipment extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'waybill_number';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Product IDs of the cargo, taken from the booking order's line items. Never null-guards away a missing product — a deleted product's id still traces the cargo. */
    public function cargoProductIds(): array
    {
        return $this->order->items()->pluck('product_id')->filter()->values()->all();
    }
}
```

- [ ] **Step 3: Check `Order::items()` and `OrderItem.product_id` exist**

```bash
grep -n "function items" app/Models/Order.php
grep -n "product_id" database/migrations/*orders_items*.php database/migrations/*order_items*.php 2>/dev/null
```

If `OrderItem` has no `product_id` column, adjust `cargoProductIds()` to pull whatever product-identifying column actually exists (e.g. `species_id`/`quote_item_id` chain) — do not invent a column. Record the actual finding in this plan file before continuing if it differs.

- [ ] **Step 4: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'waybill_number' => 'WB-'.strtoupper(Str::random(10)),
            'vehicle_id' => null,
            'driver_id' => null,
            'origin' => fake()->city(),
            'destination' => fake()->city(),
        ];
    }
}
```

- [ ] **Step 5: Run migrations against the testing DB and confirm the table exists**

Acquire the test lock, confirm `--env=testing` resolves to `cameroontimberhub_testing`, then:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan migrate --env=testing
```

Expected: migration runs, no errors.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/2026_08_29_100040_create_shipments_table.php app/Models/Shipment.php database/factories/ShipmentFactory.php
git commit -m "Add Shipment model with optional fleet linkage (gap-plan 1.5.10)"
```

## Task 4: `ShipmentService::createFromOrder()`

**Files:**
- Create: `app/Services/ShipmentService.php`
- Test: `tests/Feature/ShipmentWaybillTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Order;
use App\Services\ShipmentService;

test('ShipmentService creates a Shipment from an Order with no fleet data required', function () {
    $order = Order::factory()->has(\App\Models\OrderItem::factory()->count(2))->create();

    $shipment = app(ShipmentService::class)->createFromOrder($order);

    expect($shipment->exists)->toBeTrue()
        ->and($shipment->order_id)->toBe($order->id)
        ->and($shipment->vehicle_id)->toBeNull()
        ->and($shipment->driver_id)->toBeNull()
        ->and($shipment->waybill_number)->not->toBeEmpty();
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/ShipmentWaybillTest.php
```

Expected: FAIL (`ShipmentService` class not found).

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Str;

/**
 * The one write path from a transport booking (an Order) to a Shipment
 * (gap-plan 1.5.10). Fleet linkage is entirely optional — mirrors
 * InventoryService's opt-in pattern: a booking with no vehicle/driver data
 * yet is a normal, fully functional Shipment, not an error.
 */
class ShipmentService
{
    public function createFromOrder(Order $order, array $data = []): Shipment
    {
        return Shipment::create([
            'order_id' => $order->id,
            'waybill_number' => $data['waybill_number'] ?? $this->generateWaybillNumber(),
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'driver_id' => $data['driver_id'] ?? null,
            'origin' => $data['origin'] ?? null,
            'destination' => $data['destination'] ?? null,
        ]);
    }

    private function generateWaybillNumber(): string
    {
        do {
            $candidate = 'WB-'.strtoupper(Str::random(10));
        } while (Shipment::where('waybill_number', $candidate)->exists());

        return $candidate;
    }
}
```

- [ ] **Step 4: Run again to verify it passes**

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/ShipmentWaybillTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/ShipmentService.php tests/Feature/ShipmentWaybillTest.php
git commit -m "Add ShipmentService::createFromOrder (gap-plan 1.5.10)"
```

## Task 5: Digital waybill — QR code service + public page

**Files:**
- Create: `app/Services/ShipmentWaybillQrCodeService.php`
- Create: `app/Http/Controllers/Public/ShipmentWaybillController.php`
- Create: `resources/views/shipments/waybill.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ShipmentWaybillTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ShipmentWaybillTest.php`:

```php
use App\Models\Product;
use App\Models\Shipment;
use App\Services\ShipmentWaybillQrCodeService;

test('the QR data URI encodes the public waybill URL', function () {
    $shipment = Shipment::factory()->create();

    $uri = app(ShipmentWaybillQrCodeService::class)->dataUri($shipment);

    expect($uri)->toStartWith('data:image/svg+xml;base64,');
});

test('the public waybill page lists the cargo product IDs and renders a QR', function () {
    $product = Product::factory()->create();
    $order = Order::factory()->create();
    $order->items()->save(\App\Models\OrderItem::factory()->make(['product_id' => $product->id]));
    $shipment = Shipment::factory()->for($order)->create();

    $response = $this->get(route('shipments.waybill.show', $shipment));

    $response->assertOk();
    $response->assertSee($shipment->waybill_number);
    $response->assertSee((string) $product->id);
});
```

- [ ] **Step 2: Confirm `OrderItem.product_id` column actually exists (from Task 3 Step 3's finding)**

If Task 3 found `OrderItem` has no `product_id`, adjust this test's setup and the controller/model accordingly to the real column, and note it here.

- [ ] **Step 3: Run to verify it fails**

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/ShipmentWaybillTest.php
```

Expected: FAIL (class/route not found).

- [ ] **Step 4: Write the QR service**

```php
<?php

namespace App\Services;

use App\Models\Shipment;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Dynamic QR pointing at the shipment's live public waybill page — same
 * "point at a URL, not a snapshot" pattern as CertificateQrCodeService.
 * The page it points to lists the cargo's product IDs, satisfying gap-plan
 * 1.5.10's "QR linking cargo product IDs" without embedding a data blob
 * that would go stale if the order's items ever changed.
 */
class ShipmentWaybillQrCodeService
{
    public function waybillUrl(Shipment $shipment): string
    {
        return route('shipments.waybill.show', $shipment);
    }

    /** Returns an inline `data:image/svg+xml;base64,...` string, safe to drop straight into an <img src>. */
    public function dataUri(Shipment $shipment): string
    {
        return (new Builder(
            writer: new SvgWriter,
            data: $this->waybillUrl($shipment),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 240,
            margin: 8,
        ))->build()->getDataUri();
    }
}
```

- [ ] **Step 5: Write the controller**

```php
<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Services\ShipmentWaybillQrCodeService;
use Illuminate\View\View;

class ShipmentWaybillController extends Controller
{
    public function show(Shipment $shipment, ShipmentWaybillQrCodeService $qr): View
    {
        return view('shipments.waybill', [
            'shipment' => $shipment,
            'qrDataUri' => $qr->dataUri($shipment),
            'cargoProductIds' => $shipment->cargoProductIds(),
        ]);
    }
}
```

- [ ] **Step 6: Write the view**

```blade
<x-app-layout>
    <div class="max-w-2xl mx-auto py-10 px-4">
        <h1 class="text-xl font-semibold">Waybill {{ $shipment->waybill_number }}</h1>

        <img src="{{ $qrDataUri }}" alt="Waybill QR code" class="my-6 w-40 h-40">

        <dl class="grid grid-cols-2 gap-2 text-sm">
            <dt class="text-gray-500">Origin</dt>
            <dd>{{ $shipment->origin ?? '—' }}</dd>
            <dt class="text-gray-500">Destination</dt>
            <dd>{{ $shipment->destination ?? '—' }}</dd>
        </dl>

        <h2 class="mt-8 font-medium">Cargo (product IDs)</h2>
        <ul class="list-disc list-inside">
            @forelse ($cargoProductIds as $productId)
                <li>{{ $productId }}</li>
            @empty
                <li class="text-gray-500">No linked products on this booking.</li>
            @endforelse
        </ul>
    </div>
</x-app-layout>
```

Check `resources/views` for the actual layout component name in use (`x-app-layout` vs. something project-specific) before finalizing — grep `resources/views` for an existing public page's top line and match it.

- [ ] **Step 7: Add the route**

```php
Route::get('/shipments/{shipment:waybill_number}/waybill', [ShipmentWaybillController::class, 'show'])->name('shipments.waybill.show');
```

- [ ] **Step 8: Run to verify it passes**

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Feature/ShipmentWaybillTest.php
```

Expected: PASS.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add app/Services/ShipmentWaybillQrCodeService.php app/Http/Controllers/Public/ShipmentWaybillController.php resources/views/shipments/waybill.blade.php routes/web.php tests/Feature/ShipmentWaybillTest.php
git commit -m "Add digital waybill page + QR linking cargo product IDs (gap-plan 1.5.10)"
```

## Task 6: Full suite + GAP_PLAN.md update

- [ ] **Step 1:** Acquire the test lock, run the full suite:

```bash
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test
```

Record the pass count. Release the lock immediately after.

- [ ] **Step 2:** Acquire the GAP_PLAN.md lock, re-read `docs/GAP_PLAN.md` fresh, edit only the 1.5.10 row to `✅ DONE` with a summary and commit SHAs, commit, release the lock.

- [ ] **Step 3:** Final commit message references the plan file path.

---

## Self-Review

1. **Spec coverage:** Transport RFQ entry point (Task 2) ✓; Shipment model with nullable/optional fleet FKs (Task 3) ✓; booking→Shipment write path (Task 4) ✓; digital waybill with QR linking cargo product IDs (Task 5) ✓; RfqType additive edit (Task 1) ✓; no tracking/status logic added ✓; no touched files outside the module boundary ✓.
2. **Placeholder scan:** no TBD/TODO; every step has real code.
3. **Type consistency:** `Shipment::cargoProductIds()` used consistently in Task 3 model doc, Task 4 test, Task 5 controller/view. `ShipmentService::createFromOrder(Order $order, array $data = [])` signature consistent across Tasks 4–6. Route name `shipments.waybill.show` consistent across Tasks 5 and its test.
4. **Risk flagged for the implementer:** Task 3 Step 3 and Task 5 Step 2 both require confirming `OrderItem.product_id` actually exists before trusting the code as written — if it doesn't, the plan says explicitly what to check and adjust, not "handle appropriately."
