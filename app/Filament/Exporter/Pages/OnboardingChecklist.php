<?php

namespace App\Filament\Exporter\Pages;

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

        return [
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
                'url'      => \App\Filament\Exporter\Resources\Companies\CompanyResource::getUrl('edit', ['record' => $company]),
            ],
            [
                'label'    => 'Species / products added',
                'done'     => $company->species->isNotEmpty(),
                'detail'   => $company->species->isNotEmpty()
                    ? $company->species->count() . ' species listed'
                    : 'Add the timber species you export',
                'url'      => \App\Filament\Exporter\Resources\Companies\CompanyResource::getUrl('edit', ['record' => $company]),
            ],
            [
                'label'    => 'Contact person added',
                'done'     => $company->contacts->isNotEmpty(),
                'detail'   => $company->contacts->isNotEmpty()
                    ? $company->contacts->count() . ' contact(s) on file'
                    : 'Add at least one public contact person',
                'url'      => \App\Filament\Exporter\Resources\Companies\CompanyResource::getUrl('edit', ['record' => $company]),
            ],
            [
                'label'    => 'Gallery images uploaded',
                'done'     => $company->gallery->isNotEmpty(),
                'detail'   => $company->gallery->isNotEmpty()
                    ? $company->gallery->count() . ' image(s) uploaded'
                    : 'Add photos of your mill, yard, or products',
                'url'      => \App\Filament\Exporter\Resources\Companies\CompanyResource::getUrl('edit', ['record' => $company]),
            ],
            [
                'label'    => 'Compliance documents uploaded',
                'done'     => $company->documents->isNotEmpty(),
                'detail'   => $company->documents->isNotEmpty()
                    ? $company->documents->count() . ' document(s) uploaded'
                    : 'Upload your RCCM, export licence, and other required documents',
                'url'      => \App\Filament\Exporter\Resources\CompanyDocuments\CompanyDocumentResource::getUrl('index'),
            ],
            [
                'label'    => 'Submitted for verification',
                'done'     => $company->verificationRequests->isNotEmpty(),
                'detail'   => $company->verificationRequests->isNotEmpty()
                    ? 'Submitted — our team will review your documents'
                    : 'Submit your profile for manual review to earn a verified badge',
                'url'      => $company->verificationRequests->isEmpty()
                    ? \App\Filament\Exporter\Resources\Companies\CompanyResource::getUrl('edit', ['record' => $company])
                    : null,
            ],
        ];
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
