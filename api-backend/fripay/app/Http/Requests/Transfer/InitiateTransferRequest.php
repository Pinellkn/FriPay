<?php

namespace App\Http\Requests\Transfer;

use App\Http\Requests\BaseApiRequest;
use App\Rules\RecipientPhone;

class InitiateTransferRequest extends BaseApiRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'quote_token' => ['required', 'string', 'size:32'],
            'sender_account_id' => ['required', 'string', 'uuid', 'exists:linked_accounts,id'],
            // Numéro opérateur (+22901…) OU numéro Fripay (30 + 8 chiffres).
            'recipient_phone' => ['required', 'string', new RecipientPhone],
            'amount' => ['required', 'numeric', 'min:100'],
            'pin' => ['required', 'string', 'size:5'],
        ];
    }
}
