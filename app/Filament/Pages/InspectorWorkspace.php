<?php

namespace App\Filament\Pages;

use App\Models\Inspection;
use App\Models\Inspector;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Curated inspector landing dashboard (implementation blueprint §86): the
 * small set of things an inspector cares about most -- their pending
 * (scheduled, not yet performed) inspections, in-progress ones, and
 * recently finalised reports.
 *
 * There is no dedicated Inspector-facing Filament panel in this codebase
 * (search confirms only the `admin` and `exporter` panel providers exist —
 * see app/Providers/Filament). Inspector::user() links an Inspector profile
 * to a User record, so this page lives in the admin panel.
 *
 * Reachability note: User::canAccessPanel() gates the ENTIRE admin panel to
 * users holding one of a fixed set of staff roles (super_admin, admin,
 * verification_officer, content_manager, compliance_officer, billing_officer)
 * -- there is no narrower "inspector" role in that allowlist today, and
 * widening that shared gate is out of scope here. In practice an inspector
 * who needs panel access already carries a staff role (typically
 * compliance_officer, since inspectors are vetted, staff-adjacent
 * professionals -- see InspectorResource/InspectionResource, both gated on
 * compliance.manage). This page's own canAccess() is written for the
 * eventual case where a narrower role exists (any user with an Inspector
 * profile, not just compliance staff), and costs nothing today since
 * everyone who reaches it has already cleared the panel-level gate. Every
 * query below is scoped to the logged-in user's own Inspector row (via
 * inspector_id) so one inspector never sees another inspector's assignments.
 */
class InspectorWorkspace extends Page
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.pages.my_inspections_nav');
    }

    public function getTitle(): string
    {
        return __('messages.filament.pages.inspector_workspace_title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.compliance');
    }
    protected string $view = 'filament.pages.inspector-workspace';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;




    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->can('compliance.manage') || Inspector::query()->where('user_id', $user->getKey())->exists();
    }

    /** The logged-in user's own Inspector profile row, or null. */
    protected function currentInspector(): ?Inspector
    {
        $userId = auth()->id();

        if (! $userId) {
            return null;
        }

        return Inspector::query()->where('user_id', $userId)->first();
    }

    /** Scheduled-but-not-yet-performed inspections assigned to this inspector. */
    public function getPendingInspections(): Collection
    {
        $inspector = $this->currentInspector();

        if (! $inspector) {
            return collect();
        }

        return Inspection::query()
            ->where('inspector_id', $inspector->getKey())
            ->whereNull('performed_at')
            ->whereNull('finalised_at')
            ->with('timberLot')
            ->orderBy('scheduled_for')
            ->limit(10)
            ->get();
    }

    /** Performed but not yet finalised inspections -- currently in progress. */
    public function getInProgressInspections(): Collection
    {
        $inspector = $this->currentInspector();

        if (! $inspector) {
            return collect();
        }

        return Inspection::query()
            ->where('inspector_id', $inspector->getKey())
            ->whereNotNull('performed_at')
            ->whereNull('finalised_at')
            ->with('timberLot')
            ->orderByDesc('performed_at')
            ->limit(10)
            ->get();
    }

    /** Recently finalised reports authored by this inspector. */
    public function getRecentlyFinalisedInspections(): Collection
    {
        $inspector = $this->currentInspector();

        if (! $inspector) {
            return collect();
        }

        return Inspection::query()
            ->where('inspector_id', $inspector->getKey())
            ->whereNotNull('finalised_at')
            ->with('timberLot')
            ->orderByDesc('finalised_at')
            ->limit(10)
            ->get();
    }
}
