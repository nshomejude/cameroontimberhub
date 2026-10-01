<?php

namespace App\Http\Controllers\Api\V1;

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
 * So this endpoint mirrors the ONE real capability: uploading/replacing the
 * single `primary_image_path`. `DELETE .../images/{imageId}` and
 * `PATCH .../images/order` are DELIBERATELY NOT built — see this class's
 * and the routes file's comments for why.
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
}
