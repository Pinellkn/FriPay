<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseApiRequest;

class RegisterRequest extends BaseApiRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'regex:/^\+22901\d{8}$/', 'unique:users,phone_number'],
            // Email OBLIGATOIRE (cahier des charges §2).
            'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.regex' => 'Le numéro doit être au format +229 01 XX XX XX XX.',
            'email.required' => "L'adresse email est obligatoire.",
            'email.unique' => 'Cette adresse email est déjà utilisée par un autre compte.',
        ];
    }
}
