<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseApiRequest;

class SetPinRequest extends BaseApiRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'current_pin' => ['nullable', 'string', 'size:5'],
            'new_pin' => ['required', 'string', 'size:5'],
        ];
    }
}
