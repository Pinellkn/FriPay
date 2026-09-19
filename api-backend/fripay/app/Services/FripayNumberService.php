<?php

namespace App\Services;

use App\Models\User;

/**
 * Génération et validation du NUMÉRO FRIPAY (cahier des charges §1).
 *
 * Règles :
 *  - 10 chiffres exactement ;
 *  - préfixe fixe "30" suivi de 8 chiffres aléatoires ;
 *  - unique sur toute la base ;
 *  - attribué automatiquement à l'inscription, jamais choisi par
 *    l'utilisateur.
 *
 * Le "30" ne chevauche aucun préfixe opérateur béninois (ceux-ci sont tous
 * en "01xx", cf. table phone_prefixes), il n'y a donc aucune ambiguïté
 * possible entre un numéro FriPay et un numéro MTN/Moov/Celtiis.
 */
class FripayNumberService
{
    public const PREFIX = '30';
    public const LENGTH = 10;

    private const MAX_ATTEMPTS = 20;

    /**
     * Génère un numéro FriPay libre. Réessaie en cas de collision.
     */
    public function generate(): string
    {
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $candidate = $this->candidate();
            if (!User::where('fripay_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Impossible de générer un numéro FriPay unique après ' . self::MAX_ATTEMPTS . ' tentatives.'
        );
    }

    /**
     * Attribue un numéro FriPay à l'utilisateur s'il n'en a pas encore.
     */
    public function assignTo(User $user): string
    {
        if (!empty($user->fripay_number)) {
            return $user->fripay_number;
        }

        $number = $this->generate();
        $user->update(['fripay_number' => $number]);

        return $number;
    }

    /**
     * Vrai si la chaîne a la forme d'un numéro FriPay (30 + 8 chiffres).
     * Tolère un indicatif +229 saisi/envoyé par erreur (le numéro FriPay
     * n'en porte jamais, mais un ancien client ou une saisie utilisateur
     * peut encore le préfixer) : on le retire avant de tester le motif.
     */
    public function isFripayNumber(string $value): bool
    {
        $digits = $this->normalize($value);

        return (bool) preg_match('/^' . self::PREFIX . '\d{8}$/', $digits);
    }

    /**
     * Ne garde que les chiffres et retire un éventuel indicatif +229 de
     * tête — le numéro FriPay n'en porte jamais (cahier §1).
     */
    public function normalize(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);

        return str_starts_with($digits, '229') ? substr($digits, 3) : $digits;
    }

    private function candidate(): string
    {
        $suffixLength = self::LENGTH - strlen(self::PREFIX);
        $max = (10 ** $suffixLength) - 1;

        return self::PREFIX . str_pad((string) random_int(0, $max), $suffixLength, '0', STR_PAD_LEFT);
    }
}
