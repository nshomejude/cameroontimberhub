<?php

namespace App\Services;

use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;

/**
 * The API's fleet-scoping boundary — the token-auth counterpart of
 * `Filament\Exporter\Resources\Vehicles\VehicleResource` /
 * `Drivers\DriverResource`, mirrored field-for-field.
 *
 * Fleet management is deliberately gated NARROWER than plain `api.supplier`
 * (company membership alone). The web resources only grant CRUD to a company
 * of `OrganisationType::Logistics`, or to a member holding the
 * `logistics_partner` account role (`VehicleResource::currentUserManagesFleet()`
 * / `DriverResource::currentUserManagesFleet()` — identical logic in both).
 * A timber exporter (`type === manufacturer`/`supplier`/etc.) does not
 * operate a fleet and gets no self-service fleet CRUD on the web, so the API
 * mirrors that exactly: `eligible()` is checked in every controller action
 * (not just `EnsureApiSupplier`'s "has a company at all"), and an ineligible
 * caller gets a hard 403 — not an empty-but-valid list — matching the web's
 * `canViewAny(): false` behaviour (the resource simply isn't reachable),
 * rather than the eligibility-is-always-200 pattern used by reorder/review
 * (those are BUYER self-service checks on an object the buyer plainly owns;
 * this is a company-capability gate, the same shape as `EnsureApiSupplier`
 * itself, so it 403s like that gate does).
 */
class FleetApiScope
{
    /** The caller's own company, or null for a user with no membership (should not reach here behind api.supplier). */
    public function company(User $user): ?Company
    {
        return $user->companies()->first();
    }

    /**
     * Whether this user may manage fleet vehicles/drivers at all — mirrors
     * `VehicleResource::currentUserManagesFleet()` / `DriverResource`'s
     * identical method exactly.
     */
    public function eligible(User $user): bool
    {
        if ($user->hasRole('logistics_partner')) {
            return true;
        }

        return $user->companies()->where('type', OrganisationType::Logistics->value)->exists();
    }

    /** Aborts 403 (mirroring the web resource's canViewAny/canCreate/canEdit/canDelete: false) if the caller isn't fleet-eligible. */
    public function ensureEligible(User $user): void
    {
        if (! $this->eligible($user)) {
            abort(403, 'This endpoint is for logistics fleet operators.');
        }
    }

    /** The caller's own company's vehicles, newest first. */
    public function vehicles(User $user): Builder
    {
        $this->ensureEligible($user);

        $companyId = $this->company($user)?->getKey();

        return Vehicle::query()
            ->where('company_id', $companyId)
            ->orderByDesc('created_at');
    }

    /** One of the caller's own company's vehicles by id, or 404. */
    public function vehicle(User $user, int|string $id): Vehicle
    {
        return $this->vehicles($user)->where('id', $id)->firstOrFail();
    }

    /** The caller's own company's drivers, newest first. */
    public function drivers(User $user): Builder
    {
        $this->ensureEligible($user);

        $companyId = $this->company($user)?->getKey();

        return Driver::query()
            ->where('company_id', $companyId)
            ->orderByDesc('created_at');
    }

    /** One of the caller's own company's drivers by id, or 404. */
    public function driver(User $user, int|string $id): Driver
    {
        return $this->drivers($user)->where('id', $id)->firstOrFail();
    }
}
