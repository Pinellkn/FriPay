<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ComplaintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'reason' => $this->reason,
            'subject' => $this->subject,
            'description' => $this->description,
            'status' => $this->status,
            'linked_transaction_id' => $this->linked_transaction_id,
            'refund_requested' => (bool) $this->refund_requested,
            'refund_status' => $this->refund_status,
            'refund_amount' => $this->refund_amount !== null ? (float) $this->refund_amount : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
