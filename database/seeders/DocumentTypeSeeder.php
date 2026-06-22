<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    /** Canonical document categories (spec §4.1). */
    public const TYPES = [
        ['key' => 'business_registration', 'name' => 'Business registration (RCCM)', 'is_required' => true],
        ['key' => 'tax_clearance', 'name' => 'Tax clearance (NIU)', 'is_required' => true],
        ['key' => 'sigif_registration', 'name' => 'SIGIF registration', 'supports_sigif' => true],
        ['key' => 'forest_concession_title', 'name' => 'Forest concession title', 'supports_sigif' => true],
        ['key' => 'export_permit', 'name' => 'Export permit', 'requires_expiry' => true, 'supports_sigif' => true],
        ['key' => 'cites_permit', 'name' => 'CITES permit', 'requires_expiry' => true],
        ['key' => 'phytosanitary_certificate', 'name' => 'Phytosanitary certificate', 'requires_expiry' => true],
        ['key' => 'legality_certificate', 'name' => 'Legality certificate', 'requires_expiry' => true],
        ['key' => 'quality_certificate', 'name' => 'Quality certificate'],
        ['key' => 'other', 'name' => 'Other document', 'affects_verification' => false],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $i => $row) {
            DocumentType::updateOrCreate(['key' => $row['key']], array_merge([
                'is_required' => false,
                'requires_expiry' => false,
                'affects_verification' => true,
                'supports_sigif' => false,
                'is_active' => true,
                'sort_order' => $i,
            ], $row));
        }
    }
}
