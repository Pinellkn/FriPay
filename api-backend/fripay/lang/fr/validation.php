<?php

/*
|--------------------------------------------------------------------------
| Messages de validation (français)
|--------------------------------------------------------------------------
| Sans ce fichier, Laravel renvoie ses messages par défaut en anglais
| ("The phone number has already been taken.") directement dans le champ
| `detail` de nos réponses d'erreur RFC 7807, qui remonte tel quel dans
| l'app mobile FriPay (100% en français).
*/

return [
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'integer' => 'Le champ :attribute doit être un entier.',
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'array' => 'Le champ :attribute doit être une liste.',
    'uuid' => 'Le champ :attribute doit être un identifiant valide.',

    'unique' => 'Ce :attribute est déjà utilisé.',
    'exists' => ":attribute sélectionné est invalide.",
    'confirmed' => 'La confirmation de :attribute ne correspond pas.',
    'in' => 'La valeur sélectionnée pour :attribute est invalide.',
    'regex' => 'Le format de :attribute est invalide.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'date' => 'Le champ :attribute doit être une date valide.',

    'size' => [
        'numeric' => 'Le champ :attribute doit être égal à :size.',
        'string' => 'Le champ :attribute doit contenir exactement :size caractères.',
        'array' => 'Le champ :attribute doit contenir :size éléments.',
    ],
    'min' => [
        'numeric' => 'Le champ :attribute doit être supérieur ou égal à :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'max' => [
        'numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],
    'between' => [
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
    ],
    'gte' => [
        'numeric' => 'Le champ :attribute doit être supérieur ou égal à :value.',
    ],
    'lte' => [
        'numeric' => 'Le champ :attribute doit être inférieur ou égal à :value.',
    ],
    'after' => 'Le champ :attribute doit être une date postérieure à :date.',
    'before' => 'Le champ :attribute doit être une date antérieure à :date.',

    'attributes' => [
        'phone_number' => 'numéro de téléphone',
        'recipient_phone' => 'numéro du destinataire',
        'sender_account_id' => 'compte émetteur',
        'pin' => 'code PIN',
        'current_pin' => 'code PIN actuel',
        'new_pin' => 'nouveau code PIN',
        'code' => 'code',
        'purpose' => 'motif',
        'first_name' => 'prénom',
        'last_name' => 'nom',
        'amount' => 'montant',
        'min_amount' => 'montant minimum',
        'max_amount' => 'montant maximum',
        'quote_token' => 'jeton de devis',
        'msisdn' => 'numéro mobile money',
        'operator' => 'opérateur',
        'status' => 'statut',
        'reason' => 'motif',
    ],
];
