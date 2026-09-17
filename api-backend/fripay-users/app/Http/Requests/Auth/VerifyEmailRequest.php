<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseApiRequest;

class VerifyEmailRequest extends BaseApiRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc'],
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.digits' => 'Le code de confirmation doit contenir 6 chiffres.',
        ];
    }
}
