<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Vérification de PIN locale pour fripay-payments.
 *
 * fripay-payments partage la même base de données que fripay-users et le
 * modèle User du package fripay-common, donc pas besoin d'appel HTTP
 * inter-service : on vérifie le pin_hash directement, comme le fait
 * App\Services\AuthService::verifyPin() côté fripay-users (logique
 * dupliquée à l'identique).
 *
 * Cette classe était une dépendance du constructeur de TransferController
 * (private readonly AuthService $authService) sans jamais avoir été créée
 * dans ce service — ce qui faisait planter TOUTES les routes de
 * TransferController (y compris GET /transfers, l'historique) avec
 * "Target class [App\Services\AuthService] does not exist", car Laravel
 * doit résoudre toutes les dépendances du constructeur avant d'exécuter
 * n'importe quelle action, même celles qui n'utilisent pas $authService.
 */
class AuthService
{
    /**
     * Vérifie le PIN de l'utilisateur avant confirmation d'un transfert.
     */
    public function verifyPin(User $user, string $pin): bool
    {
        if (!$user->pin_hash) {
            return false;
        }

        return Hash::check($pin, $user->pin_hash);
    }
}
