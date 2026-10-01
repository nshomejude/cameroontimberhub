<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupplierProductResource;
use App\Services\SupplierApiScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Product photo upload over token auth.
 *
 * IMPORTANT scope note, from reading the actual Filament form: the web
 * product form (`ProductForm::configure()`) has exactly ONE image field —
 * `FileUpload::make('primary_image_path')->image()->imageEditor()
 * ->disk('public')->directory('products')` — and there is no `->maxSize()`
 * or `->acceptedFileTypes()` call on it. `Product::images()` (the
 * `ProductImage` HasMany) and the `product_images` table exist in the schema
 * and are rendered on the public detail page (`Product::galleryImages()`),
 * but there is NO Filament RelationManager, form field, or any other write
 * path anywhere in the app that lets a supplier create, delete or reorder a
 * `ProductImage` row — `grep -rl ProductImage app resources routes database`
 * turns up only the model, `Product.php`'s read-only `galleryImages()`, and
 * a factory used solely by tests/seeders. A gallery-management API (add
 * image #2, delete image #3, drag to reorder) would be inventing a feature
 * that does not exist on the web, which the task instructions for this
 * change explicitly say not to do.
 *
 * So `store` mirrors the ONE real capability: uploading/replacing the single
 * `primary_image_path`. The released mobile app also calls
 * `DELETE .../images/{image}` and `PATCH .../images/order`, so those exist
 * too, but only over what is already there: `{image}` is `primary` (clears
 * `primary_image_path`) or the id of one of THIS product's `product_images`
 * rows (as listed in SupplierProductResource `images[]`); `order` rewrites
 * `product_images.sort_order` (the primary image is always first and is
 * ignored in the list). Removing the primary image of an active product is
 * allowed — it simply has no photo until a new one is uploaded.
 *
 * The `image` validation rule (real image files only) mirrors the form's
 * `->image()` call. Since the component itself sets no size cap, a defensive
 * 10 MB ceiling is applied here — the same number this codebase already uses
 * for `CompanyDocumentController`'s uploads (`StoreCompanyDocumentRequest`) —
 * rather than leaving multipart uploads completely unbounded from the API.
 * Both surfaces now share a 5 MB cap and jpg/png/webp types (the web form
 * gained `->maxSize(5120)->acceptedFileTypes(...)`), and replacing an image
 * deletes the previous file from the public disk.
 */
class SupplierProductImageController extends Controller
{
    public function __construct(private readonly SupplierApiScope $scope) {}

    public function store(Request $request, int|string $product): JsonResponse
    {
        $record = $this->scope->product($request->user(), $product);
        SupplierProductController::assertCanManage($request, $record);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $previous = $record->primary_image_path;
        $path = $request->file('image')->store('products', 'public');

        $record->update(['primary_image_path' => $path]);

        // Replacing the primary image must not orphan the old file.
        if (filled($previous) && $previous !== $path && ! str_starts_with($previous, 'http')) {
            Storage::disk('public')->delete($previous);
        }

        return response()->json([
            'message' => 'Image uploaded.',
            'data' => new SupplierProductResource($record->fresh()->load('species')),
        ], 201);
    }

    public function destroy(Request $request, int|string $product, string $image): JsonResponse
    {
        $record = $this->scope->product($request->user(), $product);
        SupplierProductController::assertCanManage($request, $record);

        if ($image === 'primary') {
            $path = $record->primary_image_path;
            if (blank($path)) {
                throw new ApiException(404, 'not_found', 'This product has no primary image.');
            }

            $record->update(['primary_image_path' => null]);
            $this->deleteFile($path);
        } else {
            $row = ctype_digit($image) ? $record->images()->whereKey((int) $image)->first() : null;
            if ($row === null) {
                throw new ApiException(404, 'not_found', 'Image not found.');
            }

            $row->delete();
            $this->deleteFile($row->path);
        }

        return response()->json([
            'message' => 'Image removed.',
            'data' => new SupplierProductResource($record->fresh()->load('species')),
        ]);
    }

    public function reorder(Request $request, int|string $product): JsonResponse
    {
        $record = $this->scope->product($request->user(), $product);
        SupplierProductController::assertCanManage($request, $record);

        $data = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['required']]);

        $ids = collect($data['ids'])->map(fn ($id) => (string) $id)->reject(fn ($id) => $id === 'primary')->values();
        $owned = $record->images()->pluck('id')->map(fn ($id) => (string) $id);

        if ($ids->diff($owned)->isNotEmpty()) {
            throw new ApiException(422, 'validation_failed', 'Unknown image id.', ['ids' => ['One or more ids are not images of this product.']]);
        }

        foreach ($ids as $position => $id) {
            $record->images()->whereKey((int) $id)->update(['sort_order' => $position + 1]);
        }

        return response()->json([
            'message' => 'Images reordered.',
            'data' => new SupplierProductResource($record->fresh()->load('species')),
        ]);
    }

    private function deleteFile(?string $path): void
    {
        if (filled($path) && ! str_starts_with((string) $path, 'http') && ! str_starts_with(ltrim((string) $path, '/'), 'img/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
