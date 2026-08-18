<?php

namespace App\Enums;

/**
 * The commercial role a company plays in the Cameroon timber chain. Drives the
 * "Supplier Type" facet on the public supplier directory and the matching
 * chip row on mobile.
 */
enum SupplierType: string
{
    case Manufacturer = 'manufacturer';
    case Exporter = 'exporter';
    case Trader = 'trader';
    case ServiceProvider = 'service_provider';
    case LogisticsProvider = 'logistics_provider';

    public function label(): string
    {
        return match ($this) {
            self::Manufacturer => 'Manufacturer',
            self::Exporter => 'Exporter',
            self::Trader => 'Trader',
            self::ServiceProvider => 'Service Provider',
            self::LogisticsProvider => 'Logistics Provider',
        };
    }

    /** Plural label used by the mobile chip row. */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::Manufacturer => 'Manufacturers',
            self::Exporter => 'Exporters',
            self::Trader => 'Traders',
            self::ServiceProvider => 'Service Providers',
            self::LogisticsProvider => 'Logistics Providers',
        };
    }

    /** Heroicon name (without the `heroicon-o-` prefix) for the chip row. */
    public function icon(): string
    {
        return match ($this) {
            self::Manufacturer => 'building-office-2',
            self::Exporter => 'globe-alt',
            self::Trader => 'arrows-right-left',
            self::ServiceProvider => 'wrench-screwdriver',
            self::LogisticsProvider => 'truck',
        };
    }

    /** Filament badge color key. */
    public function color(): string
    {
        return match ($this) {
            self::Manufacturer => 'success',
            self::Exporter => 'info',
            self::Trader => 'warning',
            self::ServiceProvider => 'gray',
            self::LogisticsProvider => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
