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
            // Cahier §1 : numéro FriPay (préfixe 30), attribué à l'inscription.
            // Doit être disponible partout où l'app affiche l'utilisateur —
            // pas seulement dans la réponse d'inscription.
            'fripay_number' => $this->fripay_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'kyc_status' => $this->kyc_status,
            'client_type' => $this->client_type,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}