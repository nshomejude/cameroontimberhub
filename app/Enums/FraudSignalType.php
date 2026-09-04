<?php

namespace App\Enums;

/**
 * Blueprint §25 initial anti-fraud detection: the kinds of signal
 * FraudDetectionService can raise. Detection-and-alerting only — nothing
 * reading this enum should ever auto-block or auto-suspend anything; every
 * case results in a reviewable FraudSignal for a human admin.
 */
enum FraudSignalType: string
{
    case DuplicateCompany = 'duplicate_company';
    case DuplicateDocument = 'duplicate_document';
    case PricingAnomaly = 'pricing_anomaly';
    case LoginAnomaly = 'login_anomaly';

    public function label(): string
    {
        return match ($this) {
            self::DuplicateCompany => 'Duplicate Company',
            self::DuplicateDocument => 'Duplicate Document',
            self::PricingAnomaly => 'Pricing Anomaly',
            self::LoginAnomaly => 'Login Anomaly',
        };
    }
}
