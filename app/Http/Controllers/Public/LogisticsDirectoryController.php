<?php

namespace App\Http\Controllers\Public;

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * Gap-plan 1.5.9 — a directory of logistics/transport companies
 * (App\Enums\OrganisationType::Logistics), with "trusted" / "tech-enabled"
 * verification tiers layered over the shared Verification framework
 * (gap-plan 0.2). Structurally mirrors TransformationNetworkController
 * (gap-plan 1.5.3): a Company directory filtered by OrganisationType,
 * read-only, no writes, no schema changes.
 *
 * Tiers are computed, not stored (see the plan's Scope Decision):
 *  - "Trusted"      = Company::isVerified() (Verification stage verified/published)
 *  - "Tech-enabled"  = Trusted AND the company has a website_url on file
 *  - anything else  = "Unverified"
 */
class LogisticsDirectoryController extends Controller
{
    public const TIER_TECH_ENABLED = 'Tech-enabled';

    public const TIER_TRUSTED = 'Trusted';

    public const TIER_UNVERIFIED = 'Unverified';

    public function index(Request $request): View
    {
        $region = (string) $request->query('region', '');
        $tier = (string) $request->query('tier', '');

        $filtered = $this->base()
            ->when($region !== '', fn (Builder $q) => $q->where('region', $region))
            ->orderBy('legal_name')
            ->get()
            ->filter(fn (Company $company) => $tier === '' || $this->tierOf($company) === $tier)
            ->values();

        $perPage = 12;
        $page = LengthAwarePaginator::resolveCurrentPage();
        $companies = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $regionOptions = $this->base()->distinct()->orderBy('region')->pluck('region')->filter()->values();

        return view('public.logistics-directory.index', [
            'companies' => $companies,
            'region' => $region,
            'tier' => $tier,
            'regionOptions' => $regionOptions,
            'tierOptions' => [self::TIER_TECH_ENABLED, self::TIER_TRUSTED, self::TIER_UNVERIFIED],
            'tierOf' => fn (Company $company): string => $this->tierOf($company),
        ]);
    }

    /** Logistics companies -- the base set for the directory. */
    private function base(): Builder
    {
        return Company::query()
            ->where('type', OrganisationType::Logistics->value)
            ->where('status', CompanyStatus::Verified->value);
    }

    private function tierOf(Company $company): string
    {
        if (! $company->isVerified()) {
            return self::TIER_UNVERIFIED;
        }

        return $company->website_url !== null
            ? self::TIER_TECH_ENABLED
            : self::TIER_TRUSTED;
    }
}
