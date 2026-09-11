<?php

namespace App\Services;

use App\Models\Operator;
use App\Models\PhonePrefix;

class OperatorDetectionService
{
    /**
     * Detect the operator for a given phone number based on its prefix.
     */
    public function detect(string $phoneNumber): ?Operator
    {
        $phoneNumber = ltrim($phoneNumber, '+');
        
        // Try prefixes from longest to shortest for best match
        $prefixes = PhonePrefix::with('operator')
            ->orderByRaw('LENGTH(prefix) DESC')
            ->get();

        foreach ($prefixes as $prefixEntry) {
            if (str_starts_with($phoneNumber, $prefixEntry->prefix)) {
                return $prefixEntry->operator;
            }
        }

        return null;
    }

    /**
     * Normalize phone number to E.164 format.
     *
     * Depuis la réforme de numérotation du Bénin (nov. 2021), les numéros
     * comptent 10 chiffres significatifs et commencent par "01" — ce "01"
     * fait partie intégrante du numéro (ce n'est plus un préfixe de tronc
     * à retirer), contrairement à l'ancien format à 8 chiffres.
     * Ex. local "01 97 12 34 56" -> E.164 "+22901971234 56" (+229 puis
     * les 10 chiffres tels quels, sans rien retirer).
     */
    public function normalize(string $phoneNumber): string
    {
        $phoneNumber = preg_replace('/[^\d+]/', '', $phoneNumber);

        if (str_starts_with($phoneNumber, '+')) {
            return $phoneNumber;
        }

        if (str_starts_with($phoneNumber, '229')) {
            return '+' . $phoneNumber;
        }

        return '+229' . $phoneNumber;
    }

    /**
     * Validate E.164 format.
     */
    public function isValidE164(string $phoneNumber): bool
    {
        return (bool) preg_match('/^\+22901\d{8}$/', $phoneNumber);
    }
}
