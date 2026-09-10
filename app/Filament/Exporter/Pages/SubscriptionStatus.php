<?php

namespace App\Filament\Exporter\Pages;

use App\Domain\Commerce\Queries\GetCompanySubscriptionQuery;
use App\Models\Company;
use App\Models\Subscription;
use App\Support\Bus\QueryBus;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SubscriptionStatus extends Page
{
    protected string $view = 'filament.exporter.pages.subscription-status';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Subscription';

    protected static ?string $title = 'Subscription & plan';

    protected static ?int $navigationSort = 20;

    public function getCompany(): ?Company
    {
        return auth()->user()?->companies()->first();
    }

    public function getActiveSubscription(): ?Subscription
    {
        $company = $this->getCompany();

        return $company
            ? app(QueryBus::class)->dispatch(new GetCompanySubscriptionQuery($company->getKey()))
            : null;
    }
}
