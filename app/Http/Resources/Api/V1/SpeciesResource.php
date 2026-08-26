<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A species card. Only ever rendered for `published` species — the query layer
 * (SpeciesDirectoryService::base(), SearchService) applies that gate.
 *
 * `commercial_category` is a COMMERCIAL grouping and `log_export_status` /
 * `is_promoted` are informational and default to unverified; the payload keeps
 * the same `*_note` framing the web surfaces use so a client cannot mistake
 * them for MINFOF regulatory guidance.
 *
 * @mixin Species
 */
class SpeciesResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'common_name' => $this->common_name,
            'scientific_name' => $this->scientific_name,
            'family' => $this->family,
            'trade_names' => $this->trade_names,
            'commercial_category' => $this->commercial_category?->value,
            'commercial_category_label' => $this->commercial_category?->label(),
            'is_cites_listed' => (bool) $this->is_cites_listed,
            'is_promoted' => (bool) $this->is_promoted,
            'log_export_status' => $this->log_export_status?->value,
            'log_export_status_label' => $this->log_export_status?->label(),
            'log_export_status_note' => 'Informational only — verify against current MINFOF publications.',
            'density_kg_m3_min' => $this->density_kg_m3_min,
            'density_kg_m3_max' => $this->density_kg_m3_max,
            'density_range' => $this->densityRange(),
            'durability_class' => $this->durability_class,
            'janka_hardness' => $this->janka_hardness,
            'is_premium' => $this->isPremium(),
            'products_count' => $this->whenCounted('products'),
        ];
    }
}
