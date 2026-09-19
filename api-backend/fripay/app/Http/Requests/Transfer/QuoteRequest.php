<?php

namespace App\Http\Requests\Transfer;

use App\Http\Requests\BaseApiRequest;
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
            'recipient_phone' => ['required', 'string', 'regex:/^\+22901\d{8}$/'],
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
