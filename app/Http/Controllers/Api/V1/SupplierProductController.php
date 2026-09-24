<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Commands\PublishProductCommand;
use App\Enums\PriceUnit;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Exceptions\Api\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSupplierProductRequest;
use App\Http\Requests\Api\V1\UpdateSupplierProductRequest;
use App\Http\Resources\Api\V1\SupplierProductResource;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Services\SupplierApiScope;
use App\Support\Bus\CommandBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * A supplier's own catalogue management over token auth — the API
 * counterpart of `Filament\Exporter\Resources\Products\ProductResource`. The
 * mobile app's answer to the live-tester finding that suppliers had no way to
 * manage products from the app at all.
 *
 * Scoping mirrors `SupplierRfqController`/`FleetVehicleController` exactly:
 * every lookup runs through `SupplierApiScope::product()`, which 404s
 * (never 403s) a product that exists but belongs to another company — the
 * same enumeration-safety convention as the rest of this group.
 *
 * Writes reuse the exact same "publish" seam the web resource's Create/Edit
 * pages use: when a save actually transitions the listing's status to
 * `active`, it is routed through `PublishProductCommand`/`CommandBus`
 * (`App\Domain\Catalog\Commands\PublishProductHandler`), exactly as
 * `CreateProduct::handleRecordCreation()` / `EditProduct::handleRecordUpdate()`
 * do it on the web. `company_id` is always server-derived, never taken from
 * the request body, mirroring `mutateFormDataBeforeCreate()`/
 * `mutateFormDataBeforeSave()`.
 */
class SupplierProductController extends Controller
{
    public function __construct(
        private readonly SupplierApiScope $scope,
        private readonly CommandBus $commandBus,
    ) {}

    /** The caller's own company's products, ANY status, optionally filtered by `?status=`. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_column(ProductStatus::cases(), 'value'))],
        ]);

        $products = $this->scope->products($request->user())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['species'])
            ->paginate(15);

        return SupplierProductResource::collection($products);
    }

    public function store(StoreSupplierProductRequest $request): JsonResponse
    {
        /** @var Company $company */
        $company = $this->scope->company($request->user());

        $data = $request->validated();
        $data['company_id'] = $company->getKey();
        $data['status'] = $data['status'] ?? ProductStatus::Draft->value;

        if ($data['status'] === ProductStatus::Active->value) {
            $product = $this->commandBus->dispatch(new PublishProductCommand($data));
        } else {
            $product = Product::create($data);
        }

        return response()->json([
            'data' => new SupplierProductResource($product->fresh()->load('species')),
        ], 201);
    }

    public function show(Request $request, int|string $product): SupplierProductResource
    {
        $record = $this->scope->product($request->user(), $product)->load('species');

        return new SupplierProductResource($record);
    }

    public function update(UpdateSupplierProductRequest $request, int|string $product): SupplierProductResource
    {
        $record = $this->scope->product($request->user(), $product);

        $data = $request->validated();
        // company_id is never editable from the request, regardless of what
        // is submitted — mirrors EditProduct::mutateFormDataBeforeSave().
        $data['company_id'] = $record->company_id;

        $isBecomingActive = ($data['status'] ?? null) === ProductStatus::Active->value
            && $record->status !== ProductStatus::Active;

        if ($isBecomingActive) {
            $record = $this->commandBus->dispatch(new PublishProductCommand($data, $record->getKey()));
        } else {
            $record->update($data);
        }

        return new SupplierProductResource($record->fresh()->load('species'));
    }

    /**
     * Submit-for-publish. `ProductStatus` has no intermediate "pending
     * review" state — a listing goes straight from `draft` to `active`
     * (`PublishProductHandler`'s docblock: "the same
     * Product::create($data)/`$record->update($data)` call" the web page
     * makes). This endpoint is the one-way counterpart of the web's
     * `toggleStatus` table action's "Publish" half — it is deliberately
     * narrower than that button (which also lets a supplier flip an Active
     * listing back to Draft): submitting an already-`active` listing, or an
     * `archived` one (which `toggleStatus` itself refuses to render a button
     * for — `->visible(fn (Product $r) => $r->status !== ProductStatus::Archived)`),
     * is a 409 here rather than silently no-op'ing or unpublishing.
     */
    public function submit(Request $request, int|string $product): SupplierProductResource
    {
        $record = $this->scope->product($request->user(), $product);

        if ($record->status === ProductStatus::Active) {
            throw new ConflictException('This product is already active.', 'product_already_active');
        }

        if ($record->status === ProductStatus::Archived) {
            throw new ConflictException('An archived product cannot be submitted — restore it to draft first.', 'product_archived');
        }

        $updated = $this->commandBus->dispatch(new PublishProductCommand(
            ['status' => ProductStatus::Active->value, 'company_id' => $record->company_id],
            $record->getKey(),
        ));

        return new SupplierProductResource($updated->fresh()->load('species'));
    }

    /**
     * The one real "remove a listing" action the web resource offers:
     * `Filament\Actions\DeleteAction` on `EditProduct`'s header actions and
     * `DeleteBulkAction` on the table — both a soft delete via `Product`'s
     * `SoftDeletes` trait, not a distinct "Archive" action/status flip. There
     * is no separate archive button to mirror in addition to this one.
     */
    public function destroy(Request $request, int|string $product): JsonResponse
    {
        $record = $this->scope->product($request->user(), $product);
        $record->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    /**
     * Dropdown data for the create/edit forms, pulled from the real sources
     * the Filament form's own fields read from — not an invented list.
     *
     * `product_types` / `price_units` come straight from the two enums the
     * form's `Select` components use (`ProductType::options()`,
     * `PriceUnit::options()`); `species` mirrors the form's
     * `Select::make('species_id')->relationship('species', 'common_name')`.
     *
     * `grade`, `price_currency`, `certification` and `origin` are deliberately
     * ABSENT: the web form collects each of those as a plain free-text
     * `TextInput`, not a `Select`/enum, so there is no real dropdown to mirror
     * — inventing one here would let the app suggest options the web form
     * itself does not offer. Likewise there is no `incoterm` field on the
     * product form at all (that belongs to RFQs/quotes, not catalogue
     * listings), so it is not returned here either.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => [
                'product_types' => collect(ProductType::cases())->map(fn (ProductType $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                ])->all(),
                'price_units' => collect(PriceUnit::cases())->map(fn (PriceUnit $u) => [
                    'value' => $u->value,
                    'label' => $u->label(),
                ])->all(),
                'statuses' => collect(ProductStatus::cases())->map(fn (ProductStatus $s) => [
                    'value' => $s->value,
                    'label' => $s->label(),
                ])->all(),
                'species' => Species::query()
                    ->orderBy('common_name')
                    ->get(['id', 'common_name'])
                    ->map(fn (Species $s) => ['id' => $s->id, 'common_name' => $s->common_name])
                    ->all(),
            ],
        ]);
    }
}
