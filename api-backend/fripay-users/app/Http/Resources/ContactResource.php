<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_phone' => $this->contact_phone,
            'contact_name' => $this->contact_name,
            'detected_operator' => $this->detectedOperator?->code,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
