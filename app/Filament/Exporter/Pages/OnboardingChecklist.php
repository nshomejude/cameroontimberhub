<?php

namespace App\Filament\Exporter\Pages;

use App\Enums\OrganisationType;
use App\Models\Company;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

class OnboardingChecklist extends Page
{
    protected string $view = 'filament.exporter.pages.onboarding-checklist';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Onboarding';

    protected static ?string $title = 'Onboarding checklist';

    protected static ?int $navigationSort = 10;

    public function getChecklist(): array
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        /** @var Company|null $company */
        $company = $user?->companies()->first();

        if (! $company) {
            return [];
        }

        $company->loadMissing(['contacts', 'species', 'gallery', 'documents', 'verificationRequests']);

        /** @var OrganisationType|null $type */
        $type = $company->type;

        $editUrl = \App\Filament\Exporter\Resources\Companies\CompanyResource::getUrl('edit', ['record' => $company]);

        $steps = [
            [
                'label'    => 'Company profile created',
                'done'     => true,
                'detail'   => 'Your account is linked to ' . $company->name,
                'url'      => null,
            ],
            [
                'label'    => 'Basic profile completed',
                'done'     => $company->profile_completion >= 60,
                'detail'   => $company->profile_completion . '% complete — fill in description, region, website',
                'url'      => $editUrl,
            ],
        ];

        // Logistics and carbon-project companies don't deal in timber species —
        // skip the species step for them rather than showing irrelevant copy.
        if (! in_array($type, [OrganisationType::Logistics, OrganisationType::CarbonDeveloper], true)) {
            $steps[] = [
                'label'    => 'Species / products added',
                'done'     => $company->species->isNotEmpty(),
                'detail'   => $company->species->isNotEmpty()
                    ? $company->species->count() . ' species listed'
                    : match ($type) {
                        OrganisationType::Processor => 'Add the timber species you process',
                        OrganisationType::Artisan => 'Add the timber species your products use',
                        default => 'Add the timber species you export',
                    },
                'url'      => $editUrl,
            ];
        }

        $steps[] = [
            'label'    => 'Contact person added',
            'done'     => $company->contacts->isNotEmpty(),
            'detail'   => $company->contacts->isNotEmpty()
                ? $company->contacts->count() . ' contact(s) on file'
                : 'Add at least one public contact person',
            'url'      => $editUrl,
        ];

        $steps[] = [
            'label'    => 'Gallery images uploaded',
            'done'     => $company->gallery->isNotEmpty(),
            'detail'   => $company->gallery->isNotEmpty()
                ? $company->gallery->count() . ' image(s) uploaded'
                : 'Add photos of your mill, yard, or products',
            'url'      => $editUrl,
        ];

        $steps[] = [
            'label'    => 'Compliance documents uploaded',
            'done'     => $company->documents->isNotEmpty(),
            'detail'   => $company->documents->isNotEmpty()
                ? $company->documents->count() . ' document(s) uploaded'
                : match ($type) {
                    OrganisationType::Supplier => 'Upload your RCCM, export permit, and other required documents',
                    null => 'Upload your RCCM, export permit, and other required documents',
                    default => 'Upload your registration and compliance documents',
                },
            'url'      => \App\Filament\Exporter\Resources\CompanyDocuments\CompanyDocumentResource::getUrl('index'),
        ];

        $steps[] = [
            'label'    => 'Submitted for verification',
            'done'     => $company->verificationRequests->isNotEmpty(),
            'detail'   => $company->verificationRequests->isNotEmpty()
                ? 'Submitted — our team will review your documents'
                : 'Submit your profile for manual review to earn a verified badge',
            'url'      => $company->verificationRequests->isEmpty()
                ? $editUrl
                : null,
        ];

        return $steps;
    }

    public function getCompletedCount(): int
    {
        return collect($this->getChecklist())->where('done', true)->count();
    }

    public function getTotalCount(): int
    {
        return count($this->getChecklist());
    }
}
