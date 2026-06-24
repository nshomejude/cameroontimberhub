<?php

namespace App\Actions\Lead;

use App\Models\Lead;
use App\Models\RfqCompany;
use App\Services\LeadFlowService;

class CreateLeadFromRfq
{
    public function __construct(private readonly LeadFlowService $leads) {}

    public function execute(RfqCompany $routing): Lead
    {
        return $this->leads->createFromRouting($routing);
    }
}
