<?php

namespace App\Filament\Pages;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\ProviderFeeCalculator;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * /admin → Commission report (billing engine M7, plan §15).
 *
 * Total commission charged and take-rate (commission / GMV), by segment,
 * read live from `orders` — no fabricated figures. A plain table, not a
 * chart: this is a finance/ops reconciliation view, not a marketing
 * dashboard. Gated on `pricing.manage` (reused, no new permission).
 *
 * Also reports payment-provider processing fees on completed payments
 * (PayPal commission structure, part A): fees collected from buyers (passed
 * through, `provider_fee_bearer = buyer`) vs absorbed by the platform
 * (`platform`), per provider and currency — never summed across currencies.
 */
class CommissionReport extends Page
{
    protected string $view = 'filament.pages.commission-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.commission_report');
    }

    public function getTitle(): string
    {
        return __('messages.filament.nav.commission_report');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    /**
     * One row per supplier segment, aggregated over charged orders.
     *
     * @return Collection<int, array{segment: string, orders: int, gmv: string, commission: string, take_rate: string}>
     */
    public function getRows(): Collection
    {
        $charged = Order::query()
            ->where('is_commission_charged', true)
            ->with(['company.plan', 'company.currentSubscription.plan'])
            ->get();

        return $charged
            ->groupBy(fn (Order $order) => $order->company?->effectivePlan()?->segment ?? 'unknown')
            ->map(function (Collection $orders, string $segment) {
                $gmv = $orders->reduce(fn ($carry, Order $o) => bcadd($carry, (string) $o->subtotal_amount, 2), '0.00');
                $commission = $orders->reduce(fn ($carry, Order $o) => bcadd($carry, (string) $o->commission_amount, 2), '0.00');
                $credited = $orders->reduce(fn ($carry, Order $o) => bcadd($carry, (string) $o->commission_credited_amount, 2), '0.00');
                $net = bcsub($commission, $credited, 2);
                $takeRate = bccomp($gmv, '0', 2) > 0
                    ? rtrim(rtrim(number_format((float) bcdiv(bcmul($net, '100', 8), $gmv, 4), 4), '0'), '.').'%'
                    : '0%';

                return [
                    'segment' => $segment,
                    'orders' => $orders->count(),
                    'gmv' => number_format((float) $gmv, 2),
                    'commission' => number_format((float) $commission, 2),
                    'credited' => number_format((float) $credited, 2),
                    'net_commission' => number_format((float) $net, 2),
                    'take_rate' => $takeRate,
                ];
            })
            ->values();
    }

    public function getTotals(): array
    {
        $rows = $this->getRows();

        return [
            'orders' => $rows->sum('orders'),
            'gmv' => number_format((float) $rows->sum(fn ($r) => (float) str_replace(',', '', $r['gmv'])), 2),
            'net_commission' => number_format((float) $rows->sum(fn ($r) => (float) str_replace(',', '', $r['net_commission'])), 2),
        ];
    }

    /**
     * Provider fees on completed payments, one row per provider + currency.
     *
     * @return Collection<int, array{provider: string, currency: string, payments: int, charged: string, fees_collected: string, fees_absorbed: string, net: string}>
     */
    public function getProviderFeeRows(): Collection
    {
        return Payment::query()
            ->where('status', PaymentStatus::Completed->value)
            ->whereNotNull('provider_fee_bearer')
            ->selectRaw('provider, currency, count(*) as payments, sum(amount) as charged')
            ->selectRaw("sum(case when provider_fee_bearer = 'buyer' then provider_fee_amount else 0 end) as fees_collected")
            ->selectRaw("sum(case when provider_fee_bearer = 'platform' then provider_fee_amount else 0 end) as fees_absorbed")
            ->groupBy('provider', 'currency')
            ->orderBy('provider')
            ->orderBy('currency')
            ->toBase()
            ->get()
            ->map(function (object $row): array {
                $currency = strtoupper((string) $row->currency);
                $charged = bcadd((string) $row->charged, '0', 2);
                $collected = bcadd((string) $row->fees_collected, '0', 2);
                $absorbed = bcadd((string) $row->fees_absorbed, '0', 2);
                // What the platform actually keeps after the provider's cut.
                $net = bcsub(bcsub($charged, $collected, 2), $absorbed, 2);

                return [
                    'provider' => PaymentProvider::tryFrom((string) $row->provider)?->label() ?? (string) $row->provider,
                    'currency' => $currency,
                    'payments' => (int) $row->payments,
                    'charged' => ProviderFeeCalculator::format($charged, $currency),
                    'fees_collected' => ProviderFeeCalculator::format($collected, $currency),
                    'fees_absorbed' => ProviderFeeCalculator::format($absorbed, $currency),
                    'net' => ProviderFeeCalculator::format($net, $currency),
                ];
            });
    }
}
