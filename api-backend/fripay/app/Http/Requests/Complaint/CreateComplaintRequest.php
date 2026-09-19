<?php

namespace App\Http\Requests\Complaint;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Validation\Rule;

class CreateComplaintRequest extends BaseApiRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in([
                'wrong_transfer', 'not_received', 'duplicate_charge', 'account_issue', 'other',
            ])],
            'subject' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
            'linked_transaction_id' => [
                'nullable', 'string', 'uuid',
                Rule::exists('transactions', 'id')->where(function ($query) {
                    $query->where('sender_user_id', auth()->id());
                }),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'linked_transaction_id.exists' => 'Cette transaction ne vous appartient pas ou n\'existe pas.',
        ];
    }
}
