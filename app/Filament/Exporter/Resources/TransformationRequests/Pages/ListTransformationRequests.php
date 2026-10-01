<?php

namespace App\Filament\Exporter\Resources\TransformationRequests\Pages;

use App\Filament\Exporter\Resources\TransformationRequests\TransformationRequestResource;
use App\Services\TransformationRequestService;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTransformationRequests extends ListRecords
{
    protected static string $resource = TransformationRequestResource::class;

    /** Received (provider inbox) first, then Sent — scoped by the service. */
    public function getTabs(): array
    {
        $service = app(TransformationRequestService::class);
        $user = auth()->user();

        return [
            'received' => Tab::make('Received')
                ->modifyQueryUsing(fn (Builder $query) => $query->mergeConstraintsFrom($service->received($user))),
            'sent' => Tab::make('Sent')
                ->modifyQueryUsing(fn (Builder $query) => $query->mergeConstraintsFrom($service->sent($user))),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        $company = app(TransformationRequestService::class)->company(auth()->user());

        return $company?->type?->isTransformationProvider() ? 'received' : 'sent';
    }
}
