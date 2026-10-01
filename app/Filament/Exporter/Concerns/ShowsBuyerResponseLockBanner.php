<?php

declare(strict_types=1);

namespace App\Filament\Exporter\Concerns;

use App\Filament\Exporter\Resources\Companies\CompanyResource;
use App\Models\Company;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Persistent notice on the exporter Buyer requests / Leads / Quotes pages
 * while the supplier's company is pending verification: requests reach them
 * (Company::BUYER_REQUEST_STATUSES) but they cannot respond, see buyer
 * contact or move leads until verified (Company::canRespondToBuyers()).
 */
trait ShowsBuyerResponseLockBanner
{
    public static function currentSupplierCompany(): ?Company
    {
        return auth()->user()?->companies()->first();
    }

    /** True while the signed-in supplier's company may not act on buyer requests. */
    public static function buyerResponsesLocked(): bool
    {
        return ! (bool) static::currentSupplierCompany()?->canRespondToBuyers();
    }

    public function getSubheading(): string|Htmlable|null
    {
        $company = static::currentSupplierCompany();
        if ($company === null || $company->canRespondToBuyers()) {
            return parent::getSubheading();
        }

        $url = e(CompanyResource::getUrl('edit', ['record' => $company]));

        return new HtmlString(
            '<div role="alert" data-testid="buyer-response-lock-banner" style="margin-top:.5rem;padding:.75rem 1rem;border-radius:.5rem;border:1px solid #f59e0b;background:rgba(245,158,11,.1);font-size:.875rem;">'
            .'<strong>'.e(__(Company::VERIFICATION_REQUIRED_MESSAGE)).'</strong> '
            .e(__('You can review matching requests now; quoting, buyer contact details and lead updates unlock once your company is verified.')).' '
            .'<a href="'.$url.'" style="text-decoration:underline;font-weight:600;">'.e(__('Complete your company verification')).'</a>'
            .'</div>'
        );
    }
}
