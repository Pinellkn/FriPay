<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\BaseApiRequest;

class CorridorRequest extends BaseApiRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'source_operator' => 'required|string|exists:operators,code',
            'destination_operator' => 'required|string|exists:operators,code',
            'rail' => 'required|string|in:pispi,aggregator,manual',
            'aggregator_provider' => 'nullable|string|max:50',
            'priority' => 'required|integer|min:1|max:100',
            'fee_type' => 'required|string|in:fixed,percentage,tiered',
            'fee_value' => 'required|numeric|min:0',
            'fee_cap' => 'nullable|numeric|min:0',
            'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'required|numeric|min:0|gte:min_amount',
            'active' => 'boolean',
        ];
    }
}
