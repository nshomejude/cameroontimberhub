<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Certificate;
use App\Models\Company;
use App\Services\CertificateQrCodeService;
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
                    'issued_at' => $b->issued_at?->toIso8601String(),
                    'valid_until' => $b->valid_until?->toDateString(),
                    'reference_code' => $b->reference_code,
                    'verification_url' => $this->badgeVerificationUrl(),
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

    /**
     * The company's most recent live (Certificate::scopeLiveVersion())
     * Certificate row, if any exists for this subject — badges themselves
     * carry no verification_token, so there is no per-badge verification
     * link to build; a company-level Certificate is the only real,
     * QR-able verification artifact this codebase has, reusing
     * CertificateQrCodeService::verificationUrl()'s exact route pattern
     * rather than inventing a badge-specific one. Memoized per resource
     * instance since every badge in the list shares the same company.
     */
    private ?string $cachedBadgeVerificationUrl = null;

    private bool $badgeVerificationUrlResolved = false;

    private function badgeVerificationUrl(): ?string
    {
        if ($this->badgeVerificationUrlResolved) {
            return $this->cachedBadgeVerificationUrl;
        }

        $this->badgeVerificationUrlResolved = true;

        $certificate = Certificate::query()
            ->where('subject_type', Company::class)
            ->where('subject_id', $this->id)
            ->liveVersion()
            ->orderByDesc('version')
            ->first();

        return $this->cachedBadgeVerificationUrl = $certificate
            ? app(CertificateQrCodeService::class)->verificationUrl($certificate)
            : null;
    }
}
