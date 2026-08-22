<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Company;
use Illuminate\Http\Request;

/**
 * The supplier profile screen. Extends the card with the public-facing profile
 * fields only: export markets, active badges and public contacts.
 *
 * Contacts are filtered to `is_public` rows — a private contact row is staff
 * data and never reaches a buyer client.
 *
 * @mixin Company
 */
class SupplierDetailResource extends SupplierResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'description' => $this->description,
            'website_url' => $this->website_url,
            'languages' => $this->languages,
            'annual_capacity_m3' => $this->annual_capacity_m3,
            'delivery_time' => $this->deliveryTimeLabel(),
            'response_time' => $this->responseTimeLabel(),
            'member_since' => $this->memberSince(),
            'export_markets' => $this->whenLoaded(
                'exportMarkets',
                fn () => $this->exportMarkets->pluck('country_code')->values(),
            ),
            'badges' => $this->whenLoaded(
                'activeBadges',
                fn () => $this->activeBadges->map(fn ($b) => [
                    'type' => $b->badge_type->value,
                    'label' => $b->badge_type->label(),
                    'valid_until' => $b->valid_until?->toDateString(),
                ])->values(),
            ),
            'contacts' => $this->whenLoaded(
                'contacts',
                fn () => $this->contacts->where('is_public', true)->map(fn ($c) => [
                    'name' => $c->name,
                    'role' => $c->role,
                    'email' => $c->email,
                    'phone' => $c->phone,
                ])->values(),
            ),
        ]);
    }
}
