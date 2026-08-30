<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Blueprint §15 Compliance Rule Engine: a configurable rule row instead of
 * hard-coded compliance checklists in screens. This is the data model for
 * "adaptable to EU/UK/US/China/regional African markets" — a full
 * decision-rules DSL/engine is explicitly out of scope for now
 * (decision_rules is a plain-language text field).
 */
class ComplianceRule extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'required_evidence' => 'array',
            'optional_evidence' => 'array',
            'risk_factors' => 'array',
            'effective_date' => 'date',
            'review_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function regulatorySource(): BelongsTo
    {
        return $this->belongsTo(RegulatorySource::class);
    }

    /**
     * The rule-matching "engine": returns active rules applicable to a
     * given country (required) and optionally a product category/supplier
     * type. A rule with a null value on any of these dimensions is a
     * wildcard for that dimension -- it applies regardless.
     */
    public function scopeApplicableTo(
        Builder $query,
        string $countryCode,
        ?string $productCategory = null,
        ?string $supplierType = null
    ): Builder {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($countryCode) {
                $q->whereNull('country_code')->orWhere('country_code', $countryCode);
            })
            ->where(function (Builder $q) use ($productCategory) {
                $q->whereNull('product_category');
                if ($productCategory !== null) {
                    $q->orWhere('product_category', $productCategory);
                }
            })
            ->where(function (Builder $q) use ($supplierType) {
                $q->whereNull('supplier_type');
                if ($supplierType !== null) {
                    $q->orWhere('supplier_type', $supplierType);
                }
            });
    }
}
