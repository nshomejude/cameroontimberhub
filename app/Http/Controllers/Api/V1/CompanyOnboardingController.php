<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganisationType;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller's own company onboarding checklist — the API counterpart of the
 * exporter panel's {@see \App\Filament\Exporter\Pages\OnboardingChecklist}.
 *
 * This mirrors that page's `getChecklist()` EXACTLY: same steps, same
 * `done`/`detail` conditions, same skip of the "Species / products added"
 * step for `OrganisationType::Logistics`/`OrganisationType::CarbonDeveloper`.
 * `OnboardingChecklist::getChecklist()` is a Livewire page method (not a
 * plain service), so it isn't reusable from here directly — the conditions
 * are duplicated inline rather than diverging on any of them. If either
 * copy changes, the other must change with it.
 *
 * Sits behind `api.supplier` like `CompanyProfileController`/
 * `CompanyVerificationController`; company resolution and the "no company"
 * 404 follow the same convention as those two.
 */
class CompanyOnboardingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $request->user()->companies()->first();

        if ($company === null) {
            return response()->json([
                'message' => 'This account has no company.',
            ], 404);
        }

        $company->loadMissing(['contacts', 'species', 'gallery', 'documents', 'verificationRequests']);

        $steps = $this->buildSteps($company);

        return response()->json([
            'data' => [
                'steps' => $steps,
                'completed_count' => collect($steps)->where('done', true)->count(),
                'total_count' => count($steps),
            ],
        ]);
    }

    /** @return list<array{key: string, label: string, done: bool, detail: string}> */
    private function buildSteps(Company $company): array
    {
        $type = $company->type;

        $steps = [
            [
                'key' => 'profile_created',
                'label' => 'Company profile created',
                'done' => true,
                'detail' => 'Your account is linked to '.$company->name,
            ],
            [
                'key' => 'basic_profile',
                'label' => 'Basic profile completed',
                'done' => $company->profile_completion >= 60,
                'detail' => $company->profile_completion.'% complete — fill in description, region, website',
            ],
        ];

        // Logistics and carbon-project companies don't deal in timber species —
        // skip the species step for them, mirroring OnboardingChecklist exactly.
        if (! in_array($type, [OrganisationType::Logistics, OrganisationType::CarbonDeveloper], true)) {
            $steps[] = [
                'key' => 'species',
                'label' => 'Species / products added',
                'done' => $company->species->isNotEmpty(),
                'detail' => $company->species->isNotEmpty()
                    ? $company->species->count().' species listed'
                    : match ($type) {
                        OrganisationType::Processor => 'Add the timber species you process',
                        OrganisationType::Artisan => 'Add the timber species your products use',
                        default => 'Add the timber species you export',
                    },
            ];
        }

        $steps[] = [
            'key' => 'contacts',
            'label' => 'Contact person added',
            'done' => $company->contacts->isNotEmpty(),
            'detail' => $company->contacts->isNotEmpty()
                ? $company->contacts->count().' contact(s) on file'
                : 'Add at least one public contact person',
        ];

        $steps[] = [
            'key' => 'gallery',
            'label' => 'Gallery images uploaded',
            'done' => $company->gallery->isNotEmpty(),
            'detail' => $company->gallery->isNotEmpty()
                ? $company->gallery->count().' image(s) uploaded'
                : 'Add photos of your mill, yard, or products',
        ];

        $steps[] = [
            'key' => 'documents',
            'label' => 'Compliance documents uploaded',
            'done' => $company->documents->isNotEmpty(),
            'detail' => $company->documents->isNotEmpty()
                ? $company->documents->count().' document(s) uploaded'
                : match ($type) {
                    OrganisationType::Supplier => 'Upload your RCCM, export permit, and other required documents',
                    null => 'Upload your RCCM, export permit, and other required documents',
                    default => 'Upload your registration and compliance documents',
                },
        ];

        $steps[] = [
            'key' => 'verification_submitted',
            'label' => 'Submitted for verification',
            'done' => $company->verificationRequests->isNotEmpty(),
            'detail' => $company->verificationRequests->isNotEmpty()
                ? 'Submitted — our team will review your documents'
                : 'Submit your profile for manual review to earn a verified badge',
        ];

        return $steps;
    }
}
