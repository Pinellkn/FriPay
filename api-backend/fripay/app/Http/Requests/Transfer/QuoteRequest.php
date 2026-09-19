<?php

namespace App\Http\Requests\Transfer;

use App\Http\Requests\BaseApiRequest;
use App\Rules\RecipientPhone;
use Illuminate\Validation\Rule;

class QuoteRequest extends BaseApiRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'sender_account_id' => [
                'required', 'string', 'uuid',
                Rule::exists('linked_accounts', 'id')->where(function ($query) {
                    $query->where('user_id', auth()->id());
                }),
            ],
            // Numéro opérateur (+22901…) OU numéro Fripay (30 + 8 chiffres).
            'recipient_phone' => ['required', 'string', new RecipientPhone],
            'amount' => ['required', 'numeric', 'min:100', 'max:5000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'sender_account_id.exists' => 'Ce compte source ne vous appartient pas ou n\'existe pas.',
        ];
    }
}
