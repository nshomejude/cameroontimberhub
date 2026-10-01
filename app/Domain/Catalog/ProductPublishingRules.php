<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Enums\CompanyStatus;
use App\Enums\CompanyUserRole;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single home for "who may manage supplier listings" and "may this
 * listing go live" — shared by the exporter panel (ProductResource, its
 * Create/Edit pages and the table's Publish toggle) and the supplier API
 * (SupplierProductController + its FormRequests), so the two surfaces can
 * never drift.
 *
 * Roles: only company members whose pivot role can manage the company
 * (CompanyUserRole::canManage() — owner, manager) may create, edit, publish
 * or delete products. `member` is read-only. This matches the
 * `product.manage` capability UserResource already advertises to the app.
 *
 * Company status: Suspended, Rejected and Archived companies may not publish.
 * Draft/Pending companies may — their listings simply stay hidden until the
 * company is publicly visible (see Company::publicVisibilityGaps()).
 *
 * Quality minimum: enforced ONLY on publish paths (never at model level), so
 * factories/seeders that create active products directly keep working.
 */
final class ProductPublishingRules
{
    public const MIN_DESCRIPTION_LENGTH = 30;

    /** @var list<CompanyStatus> */
    public const BLOCKED_COMPANY_STATUSES = [
        CompanyStatus::Suspended,
        CompanyStatus::Rejected,
        CompanyStatus::Archived,
    ];

    /** Whether $user's role in $company allows managing its product listings. */
    public static function canManageProducts(?User $user, Company|int|null $company): bool
    {
        if ($user === null || $company === null) {
            return false;
        }

        $companyId = $company instanceof Company ? $company->getKey() : $company;

        $role = DB::table('company_user')
            ->where('user_id', $user->getKey())
            ->where('company_id', $companyId)
            ->value('role');

        return $role !== null && (CompanyUserRole::tryFrom($role)?->canManage() ?? false);
    }

    /** Human-readable reason this company may not publish, or null when it may. */
    public static function companyPublishBlock(Company $company): ?string
    {
        if (! in_array($company->status, self::BLOCKED_COMPANY_STATUSES, true)) {
            return null;
        }

        return __('Your company is :status, so listings cannot be published. Contact support to restore your account.', [
            'status' => mb_strtolower($company->status->label()),
        ]);
    }

    /**
     * Listing-quality gaps blocking publication, keyed by field name.
     * $attributes are the listing's effective values after the pending save.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public static function qualityGaps(array $attributes): array
    {
        $gaps = [];

        if (blank($attributes['name'] ?? null)) {
            $gaps['name'] = __('A product name is required to publish.');
        }

        $type = $attributes['product_type'] ?? null;
        $type = $type instanceof ProductType ? $type->value : $type;
        if ($type !== ProductType::Charcoal->value && blank($attributes['species_id'] ?? null)) {
            $gaps['species_id'] = __('A species is required to publish.');
        }

        $description = trim(strip_tags((string) ($attributes['description'] ?? '')));
        if (mb_strlen($description) < self::MIN_DESCRIPTION_LENGTH) {
            $gaps['description'] = __('A description of at least :min characters is required to publish.', ['min' => self::MIN_DESCRIPTION_LENGTH]);
        }

        if (blank($attributes['primary_image_path'] ?? null)) {
            $gaps['primary_image_path'] = __('A primary image is required to publish.');
        }

        return $gaps;
    }

    /**
     * Effective attributes of $product after applying $changes (for checking
     * quality on an update that touches only some fields).
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function mergedAttributes(?Product $product, array $changes): array
    {
        $base = $product === null ? [] : [
            'name' => $product->name,
            'product_type' => $product->product_type,
            'species_id' => $product->species_id,
            'description' => $product->description,
            'primary_image_path' => $product->primary_image_path,
        ];

        return array_merge($base, $changes);
    }
}
