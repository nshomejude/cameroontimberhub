<?php

namespace App\Enums;

/**
 * What an uploaded order document is.
 *
 * Mirrors the `order_documents_kind_check` CHECK constraint exactly. The list
 * is deliberately short and generic: the platform issues none of these papers
 * and verifies none of them, so a narrower vocabulary ("Fumigation Certificate",
 * "Insurance Certificate") would imply a validation step that does not happen.
 * The supplier's own `label` carries the specific name.
 */
enum OrderDocumentKind: string
{
    case ProofOfDelivery = 'proof_of_delivery';
    case CommercialInvoice = 'commercial_invoice';
    case PackingList = 'packing_list';
    case BillOfLading = 'bill_of_lading';
    case Certificate = 'certificate';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ProofOfDelivery => 'Proof of delivery',
            self::CommercialInvoice => 'Commercial invoice',
            self::PackingList => 'Packing list',
            self::BillOfLading => 'Bill of lading',
            self::Certificate => 'Certificate',
            self::Other => 'Other document',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
