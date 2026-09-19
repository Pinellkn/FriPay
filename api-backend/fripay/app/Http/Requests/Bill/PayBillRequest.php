<?php

namespace App\Http\Requests\Bill;

use App\Http\Requests\BaseApiRequest;

class PayBillRequest extends BaseApiRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'biller_id' => ['required', 'string', 'uuid', 'exists:billers,id'],
            'subscriber_reference' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:100'],
            'pin' => ['required', 'string', 'size:5'],
        ];
    }

    public function messages(): array
    {
        return [
            'biller_id.exists' => 'Fournisseur inconnu.',
            'amount.min' => 'Le montant minimum est de 100 FCFA.',
            'pin.size' => 'Le code PIN doit contenir 5 chiffres.',
        ];
    }
}
