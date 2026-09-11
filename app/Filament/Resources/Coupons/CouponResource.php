<?php

namespace App\Filament\Resources\Coupons;

use App\Filament\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Resources\Coupons\Pages\EditCoupon;
use App\Filament\Resources\Coupons\Pages\ListCoupons;
use App\Filament\Resources\Coupons\Schemas\CouponForm;
use App\Filament\Resources\Coupons\Tables\CouponsTable;
use App\Models\Coupon;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * /admin → Coupons (billing engine M8, plan §19 / §124).
 *
 * Data model + admin CRUD only — NOT wired into checkout yet (that would
 * touch PaymentCheckoutController, owned by a concurrent work stream in
 * this shared worktree; see App\Services\Billing\CouponCalculator
 * docblock for the documented follow-up). Gated by `pricing.manage`
 * (reused from M5, granted to super_admin + finance_officer).
 */
class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.coupons');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.coupon_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.coupon_many');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return CouponForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CouponsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoupons::route('/'),
            'create' => CreateCoupon::route('/create'),
            'edit' => EditCoupon::route('/{record}/edit'),
        ];
    }
}
