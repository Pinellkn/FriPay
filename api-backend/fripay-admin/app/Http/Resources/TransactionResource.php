<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'amount' => (float) $this->amount,
            'fee_amount' => (float) $this->fee_amount,
            'total_debited' => (float) $this->total_debited,
            'currency' => $this->currency,
            'status' => $this->status,
            'rail_used' => $this->rail_used,
            'recipient_phone' => $this->recipient_phone,
            'recipient_operator' => $this->recipientOperator?->code,
            'recipient_name' => $this->recipient_name,
            'failure_reason' => $this->failure_reason,
            'initiated_at' => $this->initiated_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
