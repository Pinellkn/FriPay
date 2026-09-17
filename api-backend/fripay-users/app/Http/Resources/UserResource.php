<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number' => $this->phone_number,
            // Numéro FriPay (cahier §1) : identité publique de l'utilisateur,
            // 10 chiffres préfixés "30". Affiché en permanence côté app.
            'fripay_number' => $this->fripay_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            // Cahier §2 : true une fois le code de confirmation email validé.
            'email_verified' => $this->email_verified_at !== null,
            'kyc_status' => $this->kyc_status,
            'client_type' => $this->client_type,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
