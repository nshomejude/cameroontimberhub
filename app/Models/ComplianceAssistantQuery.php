<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log row for App\Services\ComplianceAssistantService::ask() (blueprint
 * §35 AI Compliance Assistant). Every question asked, exactly which
 * ComplianceRule/RegulatorySource ids were retrieved to ground the answer,
 * and the answer text are recorded here so a human can review any answer
 * after the fact — this is the highest-stakes AI feature on the platform.
 */
class ComplianceAssistantQuery extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'grounding_rule_ids' => 'array',
            'grounding_source_ids' => 'array',
            'was_grounded' => 'boolean',
            'ai_was_ready' => 'boolean',
        ];
    }

    public function askedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by');
    }
}
