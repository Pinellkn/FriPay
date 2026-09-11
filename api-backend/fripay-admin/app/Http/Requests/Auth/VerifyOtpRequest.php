<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends AppHttpRequestsBaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+229\d{8}$/'],
            'code' => ['required', 'string', 'size:6'],
            'purpose' => ['required', 'string', 'in:registration,login,transaction_confirmation,password_reset'],
        ];
    }
}
