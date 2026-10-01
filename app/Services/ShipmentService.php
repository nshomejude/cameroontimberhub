<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one write path from a transport booking (an Order) to a Shipment
 * (gap-plan 1.5.10). Fleet linkage is entirely optional — mirrors
 * InventoryService's opt-in pattern: a booking with no vehicle/driver data
 * yet is a normal, fully functional Shipment, not an error.
 *
 * Callers: the exporter panel's "Create shipment / waybill" order action,
 * POST /api/v1/supplier/orders/{reference}/shipments, and
 * OrderService::transition() (auto-creates one on "shipped" when the order
 * has none yet, via ensureForOrder()).
 *
 * Carrier rules (`carrier_company_id`): taken from the chosen vehicle's /
 * driver's company, else an explicit carrier. A vehicle, driver or carrier
 * must belong either to the order's own supplier company (own fleet) or to
 * a logistics partner (OrganisationType::Logistics — the same companies
 * listed in the public logistics directory). Vehicle and driver, when both
 * given, must belong to the same company.
 */
class ShipmentService
{
    /**
     * @param  array{vehicle_id?: int|null, driver_id?: int|null, carrier_company_id?: int|null, origin?: string|null, destination?: string|null, waybill_number?: string|null}  $data
     *
     * @throws ValidationException when a vehicle/driver/carrier isn't selectable for this order
     */
    public function createFromOrder(Order $order, array $data = []): Shipment
    {
        $vehicle = ! empty($data['vehicle_id']) ? $this->selectableVehicles($order)->whereKey($data['vehicle_id'])->first() : null;
        $driver = ! empty($data['driver_id']) ? $this->selectableDrivers($order)->whereKey($data['driver_id'])->first() : null;
        $carrier = ! empty($data['carrier_company_id']) ? $this->selectableCarriers($order)->whereKey($data['carrier_company_id'])->first() : null;

        $errors = [];
        if (! empty($data['vehicle_id']) && ! $vehicle) {
            $errors['vehicle_id'] = __('logistics.errors.vehicle_unavailable');
        }
        if (! empty($data['driver_id']) && ! $driver) {
            $errors['driver_id'] = __('logistics.errors.driver_unavailable');
        }
        if (! empty($data['carrier_company_id']) && ! $carrier) {
            $errors['carrier_company_id'] = __('logistics.errors.carrier_unavailable');
        }
        if ($vehicle && $driver && $vehicle->company_id !== $driver->company_id) {
            $errors['driver_id'] = __('logistics.errors.fleet_company_mismatch');
        }

        $carrierId = $vehicle?->company_id ?? $driver?->company_id ?? $carrier?->getKey();
        if ($carrier && $carrierId !== $carrier->getKey()) {
            $errors['carrier_company_id'] = __('logistics.errors.fleet_company_mismatch');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return Shipment::create([
            'order_id' => $order->getKey(),
            'waybill_number' => $data['waybill_number'] ?? $this->generateWaybillNumber(),
            'vehicle_id' => $vehicle?->getKey(),
            'driver_id' => $driver?->getKey(),
            'carrier_company_id' => $carrierId,
            'origin' => $data['origin'] ?? null,
            'destination' => $data['destination'] ?? null,
        ]);
    }

    /** Auto-create on ship: returns the order's existing first shipment, or a bare new one. */
    public function ensureForOrder(Order $order): Shipment
    {
        return Shipment::query()->where('order_id', $order->getKey())->oldest('id')->first()
            ?? $this->createFromOrder($order);
    }

    /** Active vehicles the order's supplier may assign: own fleet + logistics partners'. */
    public function selectableVehicles(Order $order): Builder
    {
        return Vehicle::query()->where('is_active', true)
            ->whereIn('company_id', $this->selectableCarriers($order)->select('id'));
    }

    /** Active drivers the order's supplier may assign: own fleet + logistics partners'. */
    public function selectableDrivers(Order $order): Builder
    {
        return Driver::query()->where('is_active', true)
            ->whereIn('company_id', $this->selectableCarriers($order)->select('id'));
    }

    /** The order's own supplier company plus every logistics-type company. */
    public function selectableCarriers(Order $order): Builder
    {
        return Company::query()->where(fn (Builder $q) => $q
            ->whereKey($order->company_id)
            ->orWhere('type', OrganisationType::Logistics->value));
    }

    /**
     * Shipments the user's companies can see: they are the carrier, or the
     * order's supplier.
     */
    public function visibleTo(User $user): Builder
    {
        $companyIds = $user->companies()->pluck('companies.id');

        return Shipment::query()->where(fn (Builder $q) => $q
            ->whereIn('carrier_company_id', $companyIds)
            ->orWhereHas('order', fn (Builder $o) => $o->whereIn('company_id', $companyIds)));
    }

    /** Whether the user may write checkpoints: a member of the carrier or of the order's supplier company. */
    public function canRecordCheckpoints(?User $user, Shipment $shipment): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->visibleTo($user)->whereKey($shipment->getKey())->exists();
    }

    /**
     * Whether a vehicle/driver is on a shipment still moving (order not yet
     * delivered/completed/cancelled) — deleting it would orphan live tracking.
     */
    public function hasOpenAssignment(string $column, int $id): bool
    {
        return Shipment::query()->where($column, $id)
            ->whereHas('order', fn (Builder $o) => $o->whereNotIn('status', [
                OrderStatus::Delivered->value, OrderStatus::Completed->value, OrderStatus::Cancelled->value,
            ]))
            ->exists();
    }

    private function generateWaybillNumber(): string
    {
        do {
            $candidate = 'WB-'.strtoupper(Str::random(10));
        } while (Shipment::where('waybill_number', $candidate)->exists());

        return $candidate;
    }
}
