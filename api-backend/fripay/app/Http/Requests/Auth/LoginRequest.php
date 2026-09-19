<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseApiRequest;

class LoginRequest extends BaseApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Cahier §3 : deux méthodes alternatives par PIN — numéro FriPay
            // (10 chiffres, préfixe 30) OU numéro d'opérateur (+229 01…).
            // Le champ garde le nom `phone_number` pour ne rien casser côté
            // app ; sa forme exacte est tranchée dans AuthController::login.
            'phone_number' => [
                'required',
                'string',
                'regex:/^(\+22901\d{8}|30\d{8})$/',
            ],
            'pin' => ['required', 'string', 'size:5'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.regex' => 'Saisissez votre numéro FriPay (30 XX XX XX XX) '
                . 'ou votre numéro opérateur (+229 01 XX XX XX XX).',
        ];
    }
}
