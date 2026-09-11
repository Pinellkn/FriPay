<?php

namespace App\Http\Requests\Wallet;

use App\Http\Requests\BaseApiRequest;

class WithdrawRequest extends BaseApiRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'pin' => ['required', 'string', 'size:5'],
            'agent_code' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.size' => 'Le code PIN doit contenir 5 chiffres.',
            'amount.min' => 'Le montant minimum est de 1 FCFA.',
        ];
    }
}
