<?php

namespace App\Http\Requests\Transfer;

use App\Http\Requests\BaseApiRequest;

class InitiateTransferRequest extends BaseApiRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'quote_token' => ['required', 'string', 'size:32'],
            'sender_account_id' => ['required', 'string', 'uuid', 'exists:linked_accounts,id'],
            'recipient_phone' => ['required', 'string', 'regex:/^\+22901\d{8}$/'],
            'amount' => ['required', 'numeric', 'min:100'],
            'pin' => ['required', 'string', 'size:5'],
        ];
    }
}
