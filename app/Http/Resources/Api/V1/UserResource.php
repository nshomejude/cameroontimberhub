<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\CompanyUserRole;
use App\Http\Middleware\EnsureBuyerAccount;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * The authenticated account, as the mobile client may see it.
 *
 * This resource now carries the RBAC foundation the buyer/supplier/staff
 * mobile client needs: which of the three populations the account belongs
 * to, its raw spatie role names, its resolved company (for a supplier), and
 * a `capabilities` list the client can branch its UI on without
 * re-deriving the server's authorisation rules itself.
 *
 * `role` resolution mirrors EnsureBuyerAccount's "where does this user
 * belong" rule: platform staff first, then company membership, then plain
 * buyer. A user can theoretically be both staff and a company member —
 * staff wins, because /admin access implies the wider authority.
 *
 * `capabilities` is a short, explicitly-documented list. Every entry is
 * either backed by a real Gate/Policy check (noted inline) or is an
 * ad-hoc boolean computed here because no named policy exists yet for that
 * action on the buyer/supplier population — see the docblocks below. Do not
 * add an entry without saying which it is.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $role = self::resolveRole($user);
        $company = $role === 'supplier' ? self::resolveCompany($user) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'role' => $role,
            'roles' => $user->getRoleNames()->values()->all(),
            'company' => $company,
            'capabilities' => self::resolveCapabilities($user, $role, $company),
        ];
    }

    /**
     * `staff` -> `supplier` -> `buyer`, in that order. Mirrors
     * EnsureBuyerAccount's redirect rule so there is exactly one definition
     * of "which population is this user" across the web and API surfaces.
     */
    public static function resolveRole(User $user): string
    {
        if ($user->hasAnyRole(EnsureBuyerAccount::STAFF_ROLES)) {
            return 'staff';
        }

        if ($user->companies()->exists()) {
            return 'supplier';
        }

        return 'buyer';
    }

    /**
     * The user's primary company (the one pivot row flagged `is_primary`),
     * falling back to the first company membership when none is flagged
     * primary. Returns the shape the mobile client needs: id/slug/name,
     * the user's own pivot role on that company, and the company's
     * verification status.
     *
     * @return array{id: int, slug: string, name: string, role: string, status: string}|null
     */
    public static function resolveCompany(User $user): ?array
    {
        $company = $user->companies()->wherePivot('is_primary', true)->first()
            ?? $user->companies()->first();

        if ($company === null) {
            return null;
        }

        return [
            'id' => $company->getKey(),
            'slug' => $company->slug,
            'name' => $company->trade_name ?: $company->legal_name,
            'role' => $company->pivot->role,
            'status' => $company->status?->value,
        ];
    }

    /**
     * @param  array{id: int, slug: string, name: string, role: string, status: string}|null  $company
     * @return list<string>
     */
    public static function resolveCapabilities(User $user, string $role, ?array $company): array
    {
        $capabilities = [];

        // -- staff --------------------------------------------------------
        // Real: backs User::canAccessPanel('admin') / EnsureBuyerAccount's
        // staff redirect. Not an invented gate — it's the exact role list
        // that already gates the Filament admin panel.
        if ($role === 'staff') {
            $capabilities[] = 'admin.access';
        }

        // -- buyer ----------------------------------------------------------
        // Ad-hoc: none of rfqs/quotes/orders/disputes/trade-assurance/
        // messaging have a named Policy for "can a buyer act on their own
        // record" — the boundary today is the `api.buyer` middleware
        // population gate plus query-level ownership scoping
        // (`rfqs.user_id`, `orders.user_id`, Conversation::scopeForBuyer).
        // These strings mirror that population gate; they are NOT backed by
        // a Gate::define or Policy method.
        if ($role === 'buyer') {
            $capabilities[] = 'rfq.create';
            $capabilities[] = 'quote.respond';
            $capabilities[] = 'order.view';
            $capabilities[] = 'dispute.file';
            $capabilities[] = 'trade_assurance.confirm';
            // Messaging is api.buyer-only today (see routes/api.php) — a
            // supplier cannot reach /conversations yet, so this capability
            // is buyer-only until that route is opened up.
            $capabilities[] = 'message.send';
        }

        // -- supplier ---------------------------------------------------
        if ($role === 'supplier' && $company !== null) {
            // Ad-hoc: ProductPolicy's `products.manage` permission is only
            // ever granted to staff roles in RolesAndPermissionsSeeder, so
            // it does not back company-member product management. This
            // mirrors CompanyUserRole::canManage() instead — owner/manager
            // pivot roles only, not `member`.
            if (in_array($company['role'], [CompanyUserRole::Owner->value, CompanyUserRole::Manager->value], true)) {
                $capabilities[] = 'product.manage';
            }

            // Real: backed by CompanyDocumentPolicy::create(), the actual
            // policy the exporter panel's document upload flow authorises
            // against.
            $primaryCompany = Company::find($company['id']);
            if ($primaryCompany !== null && Gate::forUser($user)->allows('create', [CompanyDocument::class, $primaryCompany])) {
                $capabilities[] = 'verification.upload';
            }
        }

        return $capabilities;
    }
}
