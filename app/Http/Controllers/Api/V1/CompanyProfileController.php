<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateCompanyProfileRequest;
use App\Http\Resources\Api\V1\CompanyProfileResource;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The caller's own company profile — the API counterpart of the exporter
 * panel's "Edit company" form
 * ({@see \App\Filament\Exporter\Resources\Companies\Schemas\CompanyForm}).
 *
 * Sits behind `api.supplier` (`EnsureApiSupplier`), so only a user who
 * belongs to a company reaches this controller; a plain buyer 403s at the
 * middleware. "The caller's company" is resolved as their first
 * `company_user` membership, the same convention
 * `CompanyVerificationController`/`SupplierApiScope` already use. That
 * lookup should never legitimately come back empty this deep behind the
 * gate, but a 404 is returned defensively rather than throwing, matching
 * `CompanyVerificationController`'s own convention.
 *
 * `payment_instructions` is exposed read/write here exactly like every
 * other scalar column — this does NOT duplicate or replace
 * `OrderLifecycleService::savePaymentInstructions()`, which remains the path
 * for setting it in the context of a specific order's payment request.
 */
class CompanyProfileController extends Controller
{
    private const RELATIONS = ['species', 'exportMarkets', 'contacts', 'gallery'];

    public function show(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);

        if ($company === null) {
            return $this->noCompanyResponse();
        }

        $company->loadMissing(self::RELATIONS);

        return response()->json([
            'data' => new CompanyProfileResource($company),
        ]);
    }

    public function update(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $company = $this->resolveCompany($request);

        if ($company === null) {
            return $this->noCompanyResponse();
        }

        $validated = $request->validated();

        DB::transaction(function () use ($company, $validated): void {
            $scalars = collect($validated)
                ->only([
                    'legal_name', 'trade_name', 'description',
                    'region', 'city', 'country_code', 'address_line',
                    'email', 'phone', 'website_url', 'payment_instructions',
                ])
                ->all();

            if ($scalars !== []) {
                $company->fill($scalars);
                $company->save();
            }

            // Every collection field below behaves like a Filament Repeater
            // bound via `->relationship()`: submitting the key replaces the
            // full set of child rows, it never merges with what already
            // exists. The key must be present in the request to touch the
            // relation at all (PATCH semantics for the scalar fields above).

            if (array_key_exists('species_ids', $validated)) {
                $company->species()->sync($validated['species_ids']);
            }

            if (array_key_exists('export_markets', $validated)) {
                $company->exportMarkets()->delete();
                foreach ($validated['export_markets'] as $market) {
                    $company->exportMarkets()->create([
                        'country_code' => $market['country_code'],
                    ]);
                }
            }

            if (array_key_exists('contacts', $validated)) {
                $company->contacts()->delete();
                foreach ($validated['contacts'] as $contact) {
                    $company->contacts()->create([
                        'name' => $contact['name'],
                        'title' => $contact['title'] ?? null,
                        'email' => $contact['email'] ?? null,
                        'phone' => $contact['phone'] ?? null,
                        'whatsapp' => $contact['whatsapp'] ?? null,
                        'is_public' => $contact['is_public'] ?? true,
                    ]);
                }
            }

            if (array_key_exists('gallery', $validated)) {
                $limit = $company->maxGalleryImages();
                $rows = array_slice($validated['gallery'], 0, $limit);

                $company->gallery()->delete();
                foreach ($rows as $image) {
                    $company->gallery()->create([
                        'image_path' => $image['image_path'],
                        'caption' => $image['caption'] ?? null,
                    ]);
                }
            }
        });

        $company->refresh()->loadMissing(self::RELATIONS);

        return response()->json([
            'data' => new CompanyProfileResource($company),
        ]);
    }

    /**
     * Upload/replace the company logo or cover image.
     *
     * `CompanyForm::configure()` has two FileUpload fields for this:
     * `logo_path` (disk `public`, directory `companies/logos`) and
     * `cover_path` (disk `public`, directory `companies/covers`) — mirrored
     * exactly here, same as `SupplierProductImageController` mirrors the
     * product form's single image field. `UpdateCompanyProfileRequest`
     * intentionally does NOT accept these as multipart files (it takes a
     * pre-existing storage path string for every other write), so this is a
     * separate, dedicated upload endpoint rather than overloading PATCH.
     */
    public function uploadImage(Request $request, string $field): JsonResponse
    {
        if (! in_array($field, ['logo', 'cover'], true)) {
            return response()->json(['message' => 'Unknown image field.'], 404);
        }

        $company = $this->resolveCompany($request);

        if ($company === null) {
            return $this->noCompanyResponse();
        }

        $request->validate([
            'image' => ['required', 'image', 'max:10240'],
        ]);

        $directory = $field === 'logo' ? 'companies/logos' : 'companies/covers';
        $column = $field === 'logo' ? 'logo_path' : 'cover_path';

        $path = $request->file('image')->store($directory, 'public');

        $company->forceFill([$column => $path])->save();

        $company->loadMissing(self::RELATIONS);

        return response()->json([
            'message' => 'Image uploaded.',
            'data' => new CompanyProfileResource($company),
        ], 201);
    }

    private function resolveCompany(Request $request): ?Company
    {
        return $request->user()->companies()->first();
    }

    private function noCompanyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'This account has no company.',
        ], 404);
    }
}
