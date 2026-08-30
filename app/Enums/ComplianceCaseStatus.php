<?php

namespace App\Enums;

/**
 * Blueprint §17 Compliance Workflow's exact 10 status values.
 */
enum ComplianceCaseStatus: string
{
    case NotAssessed = 'not_assessed';
    case Incomplete = 'incomplete';
    case UnderReview = 'under_review';
    case ReadyForFurtherReview = 'ready_for_further_review';
    case ConditionallyReady = 'conditionally_ready';
    case HighRisk = 'high_risk';
    case RemediationRequired = 'remediation_required';
    case ApprovedForCthWorkflow = 'approved_for_cth_workflow';
    case Expired = 'expired';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::NotAssessed => 'Not Assessed',
            self::Incomplete => 'Incomplete',
            self::UnderReview => 'Under Review',
            self::ReadyForFurtherReview => 'Ready for Further Review',
            self::ConditionallyReady => 'Conditionally Ready',
            self::HighRisk => 'High Risk',
            self::RemediationRequired => 'Remediation Required',
            self::ApprovedForCthWorkflow => 'Approved for CTH Workflow',
            self::Expired => 'Expired',
            self::Suspended => 'Suspended',
        };
    }
}
