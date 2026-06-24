<?php

namespace App\Actions\Lead;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Services\LeadFlowService;

class UpdateLeadStatus
{
    public function __construct(private readonly LeadFlowService $leads) {}

    public function execute(Lead $lead, LeadStatus $status): Lead
    {
        return $this->leads->setLeadStatus($lead, $status);
    }
}
