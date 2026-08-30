<?php

namespace App\Enums;

/** Blueprint §10's traceability event ledger event types. */
enum LotEventType: string
{
    case SourceRegistered = 'source_registered';
    case HarvestRecorded = 'harvest_recorded';
    case TransportDispatched = 'transport_dispatched';
    case ArrivalAtProcessor = 'arrival_at_processor';
    case ProcessingStarted = 'processing_started';
    case ProcessingCompleted = 'processing_completed';
    case DryingStarted = 'drying_started';
    case DryingCompleted = 'drying_completed';
    case QualityChecked = 'quality_checked';
    case InspectionPassed = 'inspection_passed';
    case Packaged = 'packaged';
    case ContainerLoaded = 'container_loaded';
    case CustomsSubmitted = 'customs_submitted';
    case Shipped = 'shipped';
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::SourceRegistered => 'Source registered',
            self::HarvestRecorded => 'Harvest recorded',
            self::TransportDispatched => 'Transport dispatched',
            self::ArrivalAtProcessor => 'Arrival at processor',
            self::ProcessingStarted => 'Processing started',
            self::ProcessingCompleted => 'Processing completed',
            self::DryingStarted => 'Drying started',
            self::DryingCompleted => 'Drying completed',
            self::QualityChecked => 'Quality checked',
            self::InspectionPassed => 'Inspection passed',
            self::Packaged => 'Packaged',
            self::ContainerLoaded => 'Container loaded',
            self::CustomsSubmitted => 'Customs submitted',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
        };
    }
}
