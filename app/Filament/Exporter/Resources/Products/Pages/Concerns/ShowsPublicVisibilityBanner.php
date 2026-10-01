<?php

declare(strict_types=1);

namespace App\Filament\Exporter\Resources\Products\Pages\Concerns;

use App\Filament\Exporter\Resources\Companies\CompanyResource;
use App\Models\Company;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Persistent warning on the exporter Products pages while the supplier's
 * company fails Company::scopePubliclyVisible() — otherwise a "published"
 * listing silently never reaches buyers.
 */
trait ShowsPublicVisibilityBanner
{
    public function getSubheading(): string|Htmlable|null
    {
        /** @var Company|null $company */
        $company = auth()->user()?->companies()->first();
        if ($company === null) {
            return parent::getSubheading();
        }

        $gaps = $company->publicVisibilityGaps();
        if ($gaps === []) {
            return parent::getSubheading();
        }

        $items = collect($gaps)->map(fn (string $g): string => '<li>'.e($g).'</li>')->implode('');
        $url = e(CompanyResource::getUrl('edit', ['record' => $company]));

        return new HtmlString(
            '<div role="alert" data-testid="product-visibility-banner" style="margin-top:.5rem;padding:.75rem 1rem;border-radius:.5rem;border:1px solid #f59e0b;background:rgba(245,158,11,.1);font-size:.875rem;">'
            .'<strong>'.e(__('Your products are not visible to buyers yet.')).'</strong> '
            .e(__('Your company profile still needs:'))
            .'<ul style="list-style:disc;margin:.25rem 0 .25rem 1.25rem;">'.$items.'</ul>'
            .'<a href="'.$url.'" style="text-decoration:underline;font-weight:600;">'.e(__('Complete your company profile & verification')).'</a>'
            .'</div>'
        );
    }
}
