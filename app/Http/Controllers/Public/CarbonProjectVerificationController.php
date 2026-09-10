<?php

namespace App\Http\Controllers\Public;

use App\Enums\CarbonRegistryStatus;
use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Models\CarbonProject;
use App\Services\CarbonProjectQrCodeService;
use Illuminate\View\View;

/**
 * Public carbon project registry verification page (§2.6). Open to anyone
 * holding the link or scanning the QR; rate-limited (`carbon-verify`) and
 * discloses only an allow-listed set of fields. The numeric primary key is
 * never exposed — lookup is on `public_id`.
 *
 * Only `registered` / `active` projects resolve; anything else 404s with the
 * same shape so a draft/submitted/rejected project is never leaked.
 *
 * REGISTRY ONLY — no credit counters / issuance / retirement (§2.7).
 */
class CarbonProjectVerificationController extends Controller
{
    public function __construct(private readonly CarbonProjectQrCodeService $qr) {}

    public function show(string $publicId): View
    {
        $project = CarbonProject::query()
            ->where('public_id', $publicId)
            ->whereIn('registry_status', [
                CarbonRegistryStatus::Registered->value,
                CarbonRegistryStatus::Active->value,
            ])
            ->with('company:id,slug,legal_name,trade_name,status')
            ->firstOrFail();

        $company = $project->company;
        $companyVerified = $company !== null && $company->status === CompanyStatus::Verified;

        return view('public.carbon.verify', [
            'name' => $project->name,
            'publicId' => $project->public_id,
            'projectType' => ucwords(str_replace('_', ' ', (string) $project->project_type)),
            'region' => $project->region,
            'areaHectares' => $project->area_hectares,
            'registryStatusLabel' => $project->registry_status->label(),
            'developerName' => $company?->legal_name ?? $company?->trade_name,
            'developerUrl' => ($company && $companyVerified) ? route('companies.show', $company->slug) : null,
            'developerVerified' => $companyVerified,
            'boundary' => $project->boundary,
            'qrSvg' => $this->qr->svg($project),
            'verificationUrl' => $this->qr->verificationUrl($project),
        ]);
    }
}
