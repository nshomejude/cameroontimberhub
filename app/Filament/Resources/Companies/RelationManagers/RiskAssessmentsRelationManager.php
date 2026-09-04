<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Models\Company;
use App\Models\RiskAssessment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Blueprint §7 Supplier Risk Engine: read-only staff view of a company's
 * risk-assessment history. Admin/staff-facing ONLY — the blueprint is
 * explicit raw risk scores/formulas must never reach public users, so this
 * lives on the internal Companies resource, never on the exporter
 * self-service one or any public profile.
 *
 * Deliberately does NOT edit Company.php (out of this task's boundaries) to
 * add a `riskAssessments()` relation method. Filament's own
 * canViewForRecord() calls `$ownerRecord->{relationshipName}()` directly on
 * the model though, so a relation manager override alone isn't enough — the
 * relation is registered on the Company model at runtime via Eloquent's
 * resolveRelationUsing(), scoped to this class file so it never touches
 * Company.php itself.
 */
class RiskAssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'riskAssessments';

    protected static ?string $title = 'Risk assessments';

    protected static bool $relationRegistered = false;

    private static function registerRelation(): void
    {
        if (self::$relationRegistered) {
            return;
        }

        Company::resolveRelationUsing(
            'riskAssessments',
            fn (Model $company) => $company->hasMany(RiskAssessment::class),
        );

        self::$relationRegistered = true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        self::registerRelation();

        return parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getRelationship(): Relation
    {
        self::registerRelation();

        return $this->getOwnerRecord()->riskAssessments();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('composite_score')
            ->columns([
                TextColumn::make('composite_score')->label('Score')->sortable(),
                TextColumn::make('risk_band')
                    ->label('Band')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'low_concern' => 'success',
                        'moderate' => 'info',
                        'elevated' => 'warning',
                        'high', 'critical' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()->toString()),
                TextColumn::make('identity_risk')->label('Identity')->toggleable(),
                TextColumn::make('documentation_risk')->label('Docs')->toggleable(),
                TextColumn::make('forestry_origin_risk')->label('Forestry')->toggleable(),
                TextColumn::make('traceability_risk')->label('Traceability')->toggleable(),
                TextColumn::make('product_risk')->label('Product')->toggleable(),
                TextColumn::make('delivery_risk')->label('Delivery')->toggleable(),
                TextColumn::make('transaction_risk')->label('Transaction')->toggleable(),
                TextColumn::make('dispute_risk')->label('Dispute')->toggleable(),
                TextColumn::make('compliance_risk')->label('Compliance')->toggleable(),
                TextColumn::make('reputation_risk')->label('Reputation')->toggleable(),
                TextColumn::make('computed_at')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('computed_at', 'desc');
    }
}
