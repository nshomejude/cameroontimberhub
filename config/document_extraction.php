<?php

/**
 * Field schema for AI-assisted document extraction (blueprint §35).
 *
 * Editable here (not in code) so admins/compliance officers can see and
 * adjust what the AI is asked to pull from a compliance document without a
 * deploy. Each entry is passed to AiProviderContract::extractStructured()
 * as field => description; the provider is instructed to omit any field it
 * cannot support from the document text rather than guess.
 *
 * This is a REVIEW AID ONLY — nothing in App\Services\DocumentExtractionService
 * or its job/observer wiring lets an extracted value change a document's
 * verification/compliance status automatically.
 */
return [

    'fields' => [
        'document_number' => 'The official document/certificate/permit/invoice number or reference code printed on the document.',
        'issuing_authority' => 'The organisation, government body, or certification body that issued the document.',
        'issue_date' => 'The date the document was issued, in ISO 8601 (YYYY-MM-DD) format.',
        'expiry_date' => 'The date the document expires or is no longer valid, in ISO 8601 (YYYY-MM-DD) format.',
        'species_covered' => 'The timber species (common and/or scientific names) the document covers, as a short comma-separated list.',
        'quantity' => 'The quantity/volume covered by the document, including its unit (e.g. "120 m3", "40 tonnes").',
        'buyer_or_consignee' => 'The named buyer, consignee, or recipient on the document, if any.',
        'seller_or_exporter' => 'The named seller, exporter, or supplier on the document, if any.',
        'country_of_origin' => 'The country of origin or country of export named on the document.',
        'total_value' => 'The total monetary value stated on the document, including its currency (e.g. "EUR 12,500").',
    ],

    // Maximum characters of extracted document text sent to the AI provider
    // per request, to keep prompts bounded regardless of source file size.
    'max_text_length' => 12000,

];
