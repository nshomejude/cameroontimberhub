<?php

namespace App\Enums;

enum BadgeType: string
{
    case VerifiedCompany = 'verified_company';
    case VerifiedExporter = 'verified_exporter';
    case SigifRegistered = 'sigif_registered';
    case LegalTimberSupplier = 'legal_timber_supplier';
    case ExportReady = 'export_ready';
    case CitesApproved = 'cites_approved';
    case SustainabilityProfile = 'sustainability_profile';
    case PremiumMember = 'premium_member';

    public function label(): string
    {
        return match ($this) {
            self::VerifiedCompany => 'Verified Company',
            self::VerifiedExporter => 'Verified Exporter',
            self::SigifRegistered => 'SIGIF Registered',
            self::LegalTimberSupplier => 'Legal Timber Supplier',
            self::ExportReady => 'Export Ready',
            self::CitesApproved => 'CITES Approved',
            self::SustainabilityProfile => 'Sustainability Profile',
            self::PremiumMember => 'Premium Member',
        };
    }

    /** @return array<string,string> value => label, for Filament selects. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
