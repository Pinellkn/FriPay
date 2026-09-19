<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinkedAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operator' => $this->operator?->code,
            'msisdn' => $this->msisdn,
            'is_primary' => $this->is_primary,
            'status' => $this->status,
        ];
    }
}
