<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Enums\RfqCompanyStatus;
use App\Models\CompanyInquiry;
use App\Models\Lead;
use App\Models\RfqCompany;

/**
 * Creates and advances leads (the exporter inbox), and the per-exporter RFQ
 * routing lifecycle. One lead per routing (unique index) / per inquiry.
 */
class LeadFlowService
{
    public function createFromRouting(RfqCompany $routing): Lead
    {
        $rfq = $routing->rfq;

        return Lead::firstOrCreate(['rfq_company_id' => $routing->getKey()], [
            'company_id' => $routing->company_id,
            'rfq_id' => $rfq->getKey(),
            'source' => 'rfq',
            'status' => LeadStatus::New,
            'buyer_name' => $rfq->buyer_name,
            'buyer_email' => $rfq->buyer_email,
            'buyer_country_code' => $rfq->buyer_country_code,
            'last_activity_at' => now(),
        ]);
    }

    public function createFromInquiry(CompanyInquiry $inquiry): Lead
    {
        return Lead::firstOrCreate(['company_inquiry_id' => $inquiry->getKey()], [
            'company_id' => $inquiry->company_id,
            'source' => 'inquiry',
            'status' => LeadStatus::New,
            'buyer_name' => $inquiry->name,
            'buyer_email' => $inquiry->email,
            'last_activity_at' => now(),
        ]);
    }

    public function setLeadStatus(Lead $lead, LeadStatus $to): Lead
    {
        $lead->update(['status' => $to, 'last_activity_at' => now()]);

        return $lead;
    }

    public function setRoutingStatus(RfqCompany $routing, RfqCompanyStatus $to): RfqCompany
    {
        $data = ['status' => $to];

        $stamp = match ($to) {
            RfqCompanyStatus::Viewed => 'viewed_at',
            RfqCompanyStatus::Responded => 'responded_at',
            RfqCompanyStatus::Declined => 'declined_at',
            default => null,
        };
        if ($stamp) {
            $data[$stamp] = now();
        }

        $routing->update($data);

        // Responding to a routed RFQ nudges the linked lead to "contacted".
        if ($to === RfqCompanyStatus::Responded && $routing->lead && $routing->lead->status === LeadStatus::New) {
            $this->setLeadStatus($routing->lead, LeadStatus::Contacted);
        }

        return $routing;
    }
}
