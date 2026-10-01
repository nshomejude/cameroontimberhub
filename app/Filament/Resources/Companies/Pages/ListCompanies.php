<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * "Agent submissions" tab: agent-sourced companies still awaiting
     * moderation (needs_review), with a live count badge.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $agentQueue = fn (Builder $query): Builder => $query
            ->where('source', 'like', 'agent:%')
            ->where('needs_review', true);

        $pending = $agentQueue(Company::query())->count();

        return [
            'all' => Tab::make('All'),
            'agent_submissions' => Tab::make('Agent submissions')
                ->modifyQueryUsing($agentQueue)
                ->badge($pending > 0 ? $pending : null)
                ->badgeColor('warning'),
        ];
    }
}
