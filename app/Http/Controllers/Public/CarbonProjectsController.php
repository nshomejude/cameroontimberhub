<?php

namespace App\Http\Controllers\Public;

use App\Enums\OrganisationType;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\CarbonProject;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public directory of carbon projects self-posted by carbon-developer
 * companies via the exporter panel (App\Models\CarbonProject). Mirrors
 * MadeInCameroonController's structure: a filtered, paginated public
 * listing behind Company::scopePubliclyVisible().
 */
class CarbonProjectsController extends Controller
{
    /** carbon_projects.project_type check-constraint values. */
    public const PROJECT_TYPES = [
        'reforestation',
        'afforestation',
        'avoided_deforestation',
        'agroforestry',
    ];

    public function index(Request $request): View
    {
        $projectTypes = array_values(array_intersect(
            (array) $request->query('project_type', []),
            self::PROJECT_TYPES
        ));

        $projects = CarbonProject::query()
            ->where('status', ProductStatus::Active->value)
            ->whereHas('company', fn (Builder $q) => $q
                ->publiclyVisible()
                ->where('type', OrganisationType::CarbonDeveloper->value))
            ->when($projectTypes !== [], fn (Builder $q) => $q->whereIn('project_type', $projectTypes))
            ->with('company:id,slug,legal_name,trade_name,logo_path,status,region')
            ->orderByDesc('created_at')
            ->paginate(12)
            ->withQueryString();

        return view('public.carbon-projects.index', [
            'projects' => $projects,
            'filters' => [
                'project_type' => $projectTypes,
            ],
            'projectTypeOptions' => collect(self::PROJECT_TYPES)->mapWithKeys(fn (string $t) => [$t => ucwords(str_replace('_', ' ', $t))]),
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Carbon Projects', 'url' => route('carbon-projects')],
            ],
        ]);
    }

    public function show(CarbonProject $carbonProject): View
    {
        $carbonProject = CarbonProject::query()
            ->whereKey($carbonProject->getKey())
            ->where('status', ProductStatus::Active->value)
            ->whereHas('company', fn (Builder $q) => $q
                ->publiclyVisible()
                ->where('type', OrganisationType::CarbonDeveloper->value))
            ->with('company')
            ->firstOrFail();

        return view('public.carbon-projects.show', [
            'project' => $carbonProject,
            'company' => $carbonProject->company,
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Carbon Projects', 'url' => route('carbon-projects')],
                ['label' => $carbonProject->name, 'url' => route('carbon-projects.show', $carbonProject)],
            ],
        ]);
    }
}
