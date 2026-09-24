<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * The caller's own company profile — the API counterpart of the exporter
 * panel's "Edit company" form
 * ({@see \App\Filament\Exporter\Resources\Companies\Schemas\CompanyForm}).
 * Exposes every field that form edits, nothing more (no plan/subscription
 * internals, no verification workflow state — see
 * {@see \App\Http\Controllers\Api\V1\CompanyVerificationController} for
 * that).
 *
 * `logo_url`/`cover_url` reuse the model's own `logoUrl()`/`coverUrl()` (the
 * same resolution `SupplierResource` already uses for `logo_url`), so a
 * missing upload still resolves to the brand mark / a deterministic stock
 * photo rather than a null/broken URL. `gallery.*.image_url` instead reads
 * straight off the `public` disk (`storage/companies/gallery/...`), matching
 * how `resources/views/public/companies/portfolio.blade.php` already renders
 * gallery images — that field has no non-upload fallback.
 *
 * @mixin Company
 */
class CompanyProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'description' => $this->description,

            'logo_url' => $this->logoUrl(),
            'cover_url' => $this->coverUrl(),

            'region' => $this->region,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'address_line' => $this->address_line,
            'email' => $this->email,
            'phone' => $this->phone,
            'website_url' => $this->website_url,

            'payment_instructions' => $this->payment_instructions,

            'species' => $this->whenLoaded(
                'species',
                fn () => $this->species->map(fn ($s) => [
                    'id' => $s->id,
                    'common_name' => $s->common_name,
                ])->values(),
            ),

            'export_markets' => $this->whenLoaded(
                'exportMarkets',
                fn () => $this->exportMarkets->map(fn ($m) => [
                    'id' => $m->id,
                    'country_code' => $m->country_code,
                ])->values(),
            ),

            'contacts' => $this->whenLoaded(
                'contacts',
                fn () => $this->contacts->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'title' => $c->title,
                    'email' => $c->email,
                    'phone' => $c->phone,
                    'whatsapp' => $c->whatsapp,
                    'is_public' => (bool) $c->is_public,
                ])->values(),
            ),

            'gallery' => $this->whenLoaded(
                'gallery',
                fn () => $this->gallery->map(fn ($g) => [
                    'id' => $g->id,
                    'image_url' => Storage::disk('public')->url($g->image_path),
                    'caption' => $g->caption,
                ])->values(),
            ),

            'profile_completion' => (int) $this->profile_completion,
            'max_gallery_images' => $this->maxGalleryImages(),
        ];
    }
}
