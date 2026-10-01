<?php

namespace App\Filament\Exporter\Pages;

use App\Models\ReferralEarning;
use App\Models\ReferralPayoutProfile;
use App\Models\User;
use App\Services\Referrals\ReferralPayoutService;
use App\Services\Referrals\ReferralService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * /dashboard/referrals — the supplier / exporter side of "Refer & earn", so
 * a company member who refers other companies has the same tools in the web
 * dashboard that buyers have on /account/settings and the app has on
 * /api/v1/referrals. Everything is read and written through ReferralService
 * and ReferralPayoutService; this page only presents it.
 *
 * Payout details: the PayPal email (same rules and encrypted profile as the
 * API / account settings) and optional Mobile Money / bank details, which
 * finance uses to pay XAF commissions manually ("Mark paid manually" on the
 * admin Referral earnings table). Both are only shown masked here.
 *
 * Visible to every company member (the panel itself requires a company
 * membership); the data is always the signed-in user's own.
 */
class Referrals extends Page
{
    protected string $view = 'filament.exporter.pages.referrals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?int $navigationSort = 21;

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.pages.referrals_nav');
    }

    public function getTitle(): string
    {
        return __('messages.filament.pages.referrals_title');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    private static function payouts(): ReferralPayoutService
    {
        return app(ReferralPayoutService::class);
    }

    // ------------------------------------------------------------------
    // View data
    // ------------------------------------------------------------------

    public function getCode(): string
    {
        return app(ReferralService::class)->codeFor($this->user());
    }

    public function getShareUrl(): string
    {
        return route('register', ['ref' => $this->getCode()]);
    }

    public function getIntro(): string
    {
        $settings = app(ReferralService::class)->settings();

        if (! $settings->enabled) {
            return __('messages.referral_center.paused');
        }

        $percent = rtrim(rtrim(number_format((float) $settings->rate_percent, 2, '.', ''), '0'), '.');

        return __('messages.referral_center.intro', ['percent' => $percent]);
    }

    /** @return array{signed_up: int, verified: int, qualified: int} */
    public function getFunnel(): array
    {
        return app(ReferralService::class)->funnelFor($this->user());
    }

    /** @return array<string, array{earned: string, paid: string, pending: string}> */
    public function getTotals(): array
    {
        return collect(app(ReferralService::class)->totalsFor($this->user()))
            ->map(fn (array $t, string $currency) => array_map(fn (float $v) => ReferralEarning::money($v, $currency), $t))
            ->all();
    }

    /** @return Collection<int, ReferralEarning> */
    public function getEarnings(): Collection
    {
        return ReferralEarning::where('referrer_user_id', $this->user()->getKey())
            ->with('latestPayout')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    public function getProfile(): ?ReferralPayoutProfile
    {
        return ReferralPayoutProfile::forUser($this->user());
    }

    public function paypalAvailable(): bool
    {
        return self::payouts()->paypalConfigured();
    }

    /** @return list<string> */
    public function paypalCurrencies(): array
    {
        return ReferralPayoutService::paypalCurrencies();
    }

    // ------------------------------------------------------------------
    // Payout details actions
    // ------------------------------------------------------------------

    public function setPaypalEmailAction(): Action
    {
        return Action::make('setPaypalEmail')
            ->label(__('messages.referral_center.set_paypal'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->schema([
                TextInput::make('paypal_payout_email')
                    ->label(__('messages.referral_center.paypal_field'))
                    ->email()
                    ->required()
                    ->rules(ReferralPayoutService::PAYPAL_EMAIL_RULES)
                    ->autocomplete('off'),
            ])
            ->action(function (array $data): void {
                self::payouts()->setPaypalEmail($this->user(), $data['paypal_payout_email']);
                Notification::make()->title(__('messages.referral_center.paypal_saved'))->success()->send();
            });
    }

    public function removePaypalEmailAction(): Action
    {
        return Action::make('removePaypalEmail')
            ->label(__('messages.referral_center.remove_paypal'))
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn () => $this->getProfile()?->maskedPaypalEmail() !== null)
            ->action(function (): void {
                self::payouts()->setPaypalEmail($this->user(), null);
                Notification::make()->title(__('messages.referral_center.paypal_removed'))->success()->send();
            });
    }

    public function setManualDetailsAction(): Action
    {
        return Action::make('setManualDetails')
            ->label(__('messages.referral_center.set_manual'))
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->modalDescription(__('messages.referral_center.xaf_note'))
            ->schema([
                Textarea::make('manual_payout_details')
                    ->label(__('messages.referral_center.manual_field'))
                    ->helperText(__('messages.referral_center.manual_hint'))
                    ->required()
                    ->rows(3)
                    ->rules(ReferralPayoutService::MANUAL_DETAILS_RULES),
            ])
            ->action(function (array $data): void {
                self::payouts()->setManualPayoutDetails($this->user(), $data['manual_payout_details']);
                Notification::make()->title(__('messages.referral_center.manual_saved'))->success()->send();
            });
    }

    public function removeManualDetailsAction(): Action
    {
        return Action::make('removeManualDetails')
            ->label(__('messages.referral_center.remove_manual'))
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn () => $this->getProfile()?->maskedManualPayoutDetails() !== null)
            ->action(function (): void {
                self::payouts()->setManualPayoutDetails($this->user(), null);
                Notification::make()->title(__('messages.referral_center.manual_removed'))->success()->send();
            });
    }
}
