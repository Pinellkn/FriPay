<?php

namespace App\Rules;

use App\Services\FripayNumberService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numéro de destinataire accepté pour les transferts : soit un numéro
 * d'opérateur béninois (+229 01 XX XX XX XX, cf. OperatorDetectionService),
 * soit un NUMÉRO FRIPAY (30 + 8 chiffres — avec ou sans indicatif +229
 * saisi par erreur, cf. FripayNumberService).
 *
 * Avant cette règle, la regex `^\+22901\d{8}$` rejetait tout numéro Fripay
 * ("The recipient phone field format is invalid") alors que le cahier des
 * charges permet d'envoyer vers un compte Fripay via son numéro interne.
 */
class RecipientPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Le numéro du destinataire est requis.');
            return;
        }

        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';

        // 1) Numéro Fripay : 30 + 8 chiffres (l'indicatif +229 éventuel est
        //    toléré mais le numéro Fripay n'en porte jamais).
        $withoutCountry = str_starts_with($digits, '229') ? substr($digits, 3) : $digits;
        if (preg_match('/^30\d{8}$/', $withoutCountry)) {
            return;
        }

        // 2) Numéro d'opérateur béninois : +229 01 + 8 chiffres.
        if (preg_match('/^\+22901\d{8}$/', $value)) {
            return;
        }

        $fail('Le numéro du destinataire doit être un numéro béninois '
            . '(+229 01 XX XX XX XX) ou un numéro Fripay (30 + 8 chiffres).');
    }
}
