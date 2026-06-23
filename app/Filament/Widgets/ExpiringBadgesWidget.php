<?php

namespace App\Filament\Widgets;

use App\Enums\BadgeStatus;
use App\Models\VerificationBadge;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ExpiringBadgesWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('companies.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Badges expiring within 30 days')
            ->description('Active badges approaching their valid_until date')
            ->query(
                fn (): Builder => VerificationBadge::query()
                    ->where('status', BadgeStatus::Active->value)
                    ->whereNotNull('valid_until')
                    ->whereDate('valid_until', '<=', now()->addDays(30))
                    ->whereDate('valid_until', '>=', today())
                    ->with('company:id,legal_name,trade_name,slug')
                    ->oldest('valid_until')
            )
            ->columns([
                TextColumn::make('company.name')
                    ->label('Company')
                    ->url(fn (VerificationBadge $b) => $b->company
                        ? \App\Filament\Resources\Companies\CompanyResource::getUrl('edit', ['record' => $b->company])
                        : null),
                TextColumn::make('badge_type')
                    ->label('Badge')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) ? ucwords(str_replace('_', ' ', $state->value)) : (string) $state),
                TextColumn::make('valid_until')->label('Expires')->date('d M Y')
                    ->color(fn (VerificationBadge $b): string => $b->valid_until->diffInDays() <= 7 ? 'danger' : 'warning'),
                TextColumn::make('issued_at')->label('Issued')->date('d M Y'),
            ])
            ->paginated([5, 10]);
    }
}
