<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OrderDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One document attached to an order (proof of delivery, invoice, packing
 * list, ...) — visible to both the buyer and the supplying company on that
 * order. `download_url` is this same v1 API, not the web signed-URL route,
 * for the same reason `CompanyDocumentResource` gives: the web route sits
 * behind session `auth`, which a Sanctum-token mobile client never carries.
 *
 * @mixin OrderDocument
 */
class OrderDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind?->value,
            'label' => $this->displayName(),
            'original_filename' => $this->original_filename,
            'file_size' => $this->size_bytes,
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'download_url' => route('api.v1.orders.documents.download', [
                'orderReference' => $this->order?->reference_code,
                'document' => $this->id,
            ]),
        ];
    }
}
