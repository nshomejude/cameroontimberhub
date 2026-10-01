<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\OrganisationType;
use App\Enums\ShipmentCarrierStatus;
use App\Enums\TrackingCheckpointStatus;
use App\Models\CheckpointUpdate;
use App\Models\Company;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\ShipmentAssignedNotification;
use App\Notifications\ShipmentBookingAcceptedNotification;
use App\Notifications\ShipmentBookingDeclinedNotification;
use App\Notifications\ShipmentDeliveredNotification;
use App\Notifications\ShipmentUpdateNotification;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
    /** At most one in-transit buyer notification per shipment per this many hours. */
    public const IN_TRANSIT_COALESCE_HOURS = 6;

    /**
     * @param  array{vehicle_id?: int|null, driver_id?: int|null, carrier_company_id?: int|null, mode?: 'assign'|'request', origin?: string|null, destination?: string|null, waybill_number?: string|null}  $data
     *
     * @throws ValidationException when a vehicle/driver/carrier isn't selectable for this order
     */
    public function createFromOrder(Order $order, array $data = []): Shipment
    {
        $assignment = $this->resolveAssignment($order, $data);

        $shipment = Shipment::create([
            'order_id' => $order->getKey(),
            'waybill_number' => $data['waybill_number'] ?? $this->generateWaybillNumber(),
            ...$assignment,
            'carrier_status' => $this->initialCarrierStatus($order, $assignment['carrier_company_id'], $data['mode'] ?? self::MODE_ASSIGN),
            'origin' => $data['origin'] ?? null,
            'destination' => $data['destination'] ?? null,
        ]);

        $this->notifyCarrierAssigned($shipment, null);

        return $shipment;
    }

    /** Supplier assigns the carrier directly (no carrier action needed). */
    public const MODE_ASSIGN = 'assign';

    /** Supplier requests a booking the carrier must accept or decline. */
    public const MODE_REQUEST = 'request';

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_ASSIGN, self::MODE_REQUEST];
    }

    /** carrier_status for a freshly chosen carrier: null for none / own fleet. */
    private function initialCarrierStatus(Order $order, ?int $carrierId, string $mode): ?ShipmentCarrierStatus
    {
        if ($carrierId === null || $carrierId === $order->company_id) {
            return null;
        }

        return $mode === self::MODE_REQUEST ? ShipmentCarrierStatus::Pending : ShipmentCarrierStatus::Assigned;
    }

    /**
     * Carrier accepts a pending booking request; the supplier's members are
     * told. Only a member of the shipment's carrier company may call this.
     *
     * @throws DomainException when the booking is not pending
     */
    public function acceptBooking(Shipment $shipment, User $actor): Shipment
    {
        $this->assertPendingBooking($shipment);

        $shipment->fill([
            'carrier_status' => ShipmentCarrierStatus::Accepted,
            'carrier_decline_reason' => null,
            'carrier_responded_at' => now(),
        ])->save();

        $this->notifySupplier($shipment, new ShipmentBookingAcceptedNotification($shipment), $actor);

        return $shipment;
    }

    /**
     * Carrier declines a pending booking request: the carrier, plus any
     * vehicle/driver belonging to it, is cleared from the shipment, and the
     * supplier's members are told (with the reason) so they can re-book.
     *
     * @throws DomainException when the booking is not pending
     */
    public function declineBooking(Shipment $shipment, User $actor, ?string $reason = null): Shipment
    {
        $this->assertPendingBooking($shipment);

        $carrierId = $shipment->carrier_company_id;
        $carrierName = $shipment->carrierCompany?->name;
        $attributes = [
            'carrier_company_id' => null,
            'carrier_status' => ShipmentCarrierStatus::Declined,
            'carrier_decline_reason' => $reason,
            'carrier_responded_at' => now(),
        ];
        if ($shipment->vehicle_id !== null && $shipment->vehicle?->company_id === $carrierId) {
            $attributes['vehicle_id'] = null;
        }
        if ($shipment->driver_id !== null && $shipment->driver?->company_id === $carrierId) {
            $attributes['driver_id'] = null;
        }

        $shipment->fill($attributes)->save();
        $shipment->unsetRelation('carrierCompany')->unsetRelation('vehicle')->unsetRelation('driver');

        $this->notifySupplier($shipment, new ShipmentBookingDeclinedNotification($shipment, (string) $carrierName, $reason), $actor);

        return $shipment;
    }

    /** Whether the user is a member of the shipment's carrier company (and not the order's supplier). */
    public function isCarrierMember(?User $user, Shipment $shipment): bool
    {
        if ($user === null || $shipment->carrier_company_id === null) {
            return false;
        }

        return $user->companies()->whereKey($shipment->carrier_company_id)->exists();
    }

    /** Whether the user is a member of the order's supplier company. */
    public function isSupplierMember(?User $user, Shipment $shipment): bool
    {
        $supplierId = $shipment->order?->company_id;

        return $user !== null && $supplierId !== null && $user->companies()->whereKey($supplierId)->exists();
    }

    private function assertPendingBooking(Shipment $shipment): void
    {
        if ($shipment->carrier_status !== ShipmentCarrierStatus::Pending || $shipment->carrier_company_id === null) {
            throw new DomainException(__('logistics.errors.booking_not_pending'));
        }
    }

    private function notifySupplier(Shipment $shipment, BaseNotification $notification, ?User $actor): void
    {
        $users = ($shipment->order?->company?->users()->get() ?? collect())
            ->reject(fn (User $u) => $actor !== null && $u->is($actor));

        $this->safely(fn () => Notification::send($users, $notification));
    }

    /**
     * Temporary (30 min) signed web URL streaming a checkpoint photo — minted
     * only on pages that already authorised the viewer (buyer order page,
     * exporter shipment view). Null when the checkpoint has no photo.
     */
    public function photoSignedUrl(Shipment $shipment, CheckpointUpdate $checkpoint): ?string
    {
        if ($checkpoint->photo_path === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            'shipments.checkpoints.photo',
            now()->addMinutes(30),
            ['shipment' => $shipment->getKey(), 'checkpoint' => $checkpoint->getKey()],
        );
    }

    /**
     * Stream a checkpoint's photo from the private local disk with its real
     * content type and a private cache header. 404 when the checkpoint is
     * not this shipment's, has no photo, or the file is gone.
     */
    public function photoResponse(Shipment $shipment, int $checkpointId): Response
    {
        $checkpoint = CheckpointUpdate::forTrackable($shipment)->whereKey($checkpointId)->first();
        $disk = Storage::disk('local');

        abort_if($checkpoint?->photo_path === null || ! $disk->exists($checkpoint->photo_path), 404);

        return $disk->response($checkpoint->photo_path, null, [
            'Content-Type' => $disk->mimeType($checkpoint->photo_path) ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=1800',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Edit an existing shipment's fleet/carrier/route (PATCH
     * /api/v1/supplier/shipments/{id} and the exporter "Assign carrier /
     * vehicle" action). Only keys present in `$data` change; the merged
     * vehicle/driver/carrier set is re-validated with exactly the rules of
     * createFromOrder(). Typical use: the bare shipment auto-created on ship
     * (ensureForOrder()) getting its carrier afterwards.
     *
     * @param  array{vehicle_id?: int|null, driver_id?: int|null, carrier_company_id?: int|null, mode?: 'assign'|'request', origin?: string|null, destination?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function updateAssignment(Shipment $shipment, array $data): Shipment
    {
        $order = $shipment->order()->firstOrFail();
        $previousCarrier = $shipment->carrier_company_id;

        $merged = [];
        foreach (['vehicle_id', 'driver_id'] as $key) {
            $merged[$key] = array_key_exists($key, $data) ? $data[$key] : $shipment->{$key};
        }
        // The stored carrier is derived from fleet when fleet is set; only
        // carry it over as an explicit choice when no fleet is involved, so
        // swapping to another company's vehicle doesn't trip the mismatch rule.
        $merged['carrier_company_id'] = array_key_exists('carrier_company_id', $data)
            ? $data['carrier_company_id']
            : (empty($merged['vehicle_id']) && empty($merged['driver_id']) ? $shipment->carrier_company_id : null);

        $attributes = $this->resolveAssignment($order, $merged);
        foreach (['origin', 'destination'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }

        // Booking state: a changed carrier starts over (assigned or pending per
        // `mode`). Same carrier + explicit mode=assign upgrades a pending
        // request to a direct assignment (re-notified as assigned); a
        // request never downgrades an existing assignment/acceptance.
        $mode = $data['mode'] ?? self::MODE_ASSIGN;
        $carrierChanged = $attributes['carrier_company_id'] !== $previousCarrier;
        $upgraded = false;
        if ($carrierChanged) {
            $attributes['carrier_status'] = $this->initialCarrierStatus($order, $attributes['carrier_company_id'], $mode);
            $attributes['carrier_decline_reason'] = null;
            $attributes['carrier_responded_at'] = null;
        } elseif ($mode === self::MODE_ASSIGN && array_key_exists('mode', $data)
            && $shipment->carrier_status === ShipmentCarrierStatus::Pending) {
            $attributes['carrier_status'] = ShipmentCarrierStatus::Assigned;
            $upgraded = true;
        }

        $shipment->fill($attributes)->save();

        $this->notifyCarrierAssigned($shipment, $upgraded ? null : $previousCarrier);

        return $shipment;
    }

    /**
     * Validate a vehicle/driver/carrier selection for this order and return
     * the columns to store.
     *
     * @param  array<string, mixed>  $data
     * @return array{vehicle_id: int|null, driver_id: int|null, carrier_company_id: int|null}
     *
     * @throws ValidationException
     */
    private function resolveAssignment(Order $order, array $data): array
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

        return [
            'vehicle_id' => $vehicle?->getKey(),
            'driver_id' => $driver?->getKey(),
            'carrier_company_id' => $carrierId,
        ];
    }

    /**
     * Tell a newly assigned THIRD-PARTY carrier's users they were booked.
     * Own-fleet assignments (carrier = the order's supplier) and unchanged
     * carriers notify nobody. A direct assignment is informational; a
     * booking request (carrier_status=pending) asks the carrier to accept or
     * decline (acceptBooking()/declineBooking()).
     */
    private function notifyCarrierAssigned(Shipment $shipment, ?int $previousCarrierId): void
    {
        $carrierId = $shipment->carrier_company_id;
        $order = $shipment->order;

        if ($carrierId === null || $carrierId === $previousCarrierId || $order === null || $carrierId === $order->company_id) {
            return;
        }

        $carrier = Company::query()->find($carrierId);
        if ($carrier === null) {
            return;
        }

        $isRequest = $shipment->carrier_status === ShipmentCarrierStatus::Pending;

        $this->safely(fn () => Notification::send($carrier->users()->get(), new ShipmentAssignedNotification($shipment, $isRequest)));
    }

    /**
     * Post-checkpoint notifications (web capture + API). Never changes the
     * order status.
     *
     *  - `delivered`: the order's supplier company users (minus whoever
     *    recorded it) and the buyer get ShipmentDeliveredNotification
     *    ("goods delivered — confirm").
     *  - `in_transit`: the buyer gets ShipmentUpdateNotification, coalesced
     *    to at most one per shipment per IN_TRANSIT_COALESCE_HOURS.
     */
    public function notifyCheckpointRecorded(Shipment $shipment, CheckpointUpdate $checkpoint, ?User $recorder = null): void
    {
        $order = $shipment->order()->with(['company', 'user'])->first();
        if ($order === null) {
            return;
        }

        $isRecorder = fn (?User $u): bool => $u !== null && $recorder !== null && $u->is($recorder);

        if ($checkpoint->status === TrackingCheckpointStatus::Delivered) {
            $supplierUsers = ($order->company?->users()->get() ?? collect())
                ->reject(fn (User $u) => $isRecorder($u));

            $this->safely(fn () => Notification::send($supplierUsers, new ShipmentDeliveredNotification($shipment, ShipmentDeliveredNotification::AUDIENCE_SUPPLIER)));

            if ($order->user !== null && ! $isRecorder($order->user)) {
                $this->safely(fn () => $order->user->notify(new ShipmentDeliveredNotification($shipment, ShipmentDeliveredNotification::AUDIENCE_BUYER)));
            }

            return;
        }

        if ($checkpoint->status === TrackingCheckpointStatus::InTransit && $order->user !== null
            && Cache::add('shipment-in-transit-notified:'.$shipment->getKey(), true, now()->addHours(self::IN_TRANSIT_COALESCE_HOURS))) {
            $this->safely(fn () => $order->user->notify(new ShipmentUpdateNotification($order)));
        }
    }

    /** Notification failures must never fail the write that triggered them. */
    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('ShipmentService notification failed', ['exception' => $e->getMessage()]);
        }
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

    /**
     * Whether the user may write checkpoints: a member of the order's
     * supplier company always; a member of the carrier company only while
     * the booking is live (carrier_status null/legacy, assigned or accepted
     * — never while a booking request is still pending).
     */
    public function canRecordCheckpoints(?User $user, Shipment $shipment): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->isSupplierMember($user, $shipment)) {
            return true;
        }

        return $this->isCarrierMember($user, $shipment)
            && ($shipment->carrier_status === null || $shipment->carrier_status->isActive());
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
