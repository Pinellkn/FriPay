<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OperatorDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vérification publique de l'existence d'un compte FriPay pour un numéro.
 *
 * Utilisé par la page web de paiement (web1, /pay/{token}) pour le bloc
 * « Payer via FriPay » : on demande le numéro du payeur, on vérifie s'il
 * possède un compte, et la page le redirige vers l'application — ou vers
 * les stores (Play Store / App Store) dans le cas contraire.
 *
 * PUBLIC (sans auth Sanctum) mais fortement rate-limité par IP, et la
 * réponse est volontairement minimale : un seul booléen, jamais de nom ni
 * de solde — pas d'énumération exploitable.
 */
class PhoneCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
        ]);

        $digits = preg_replace('/\D/', '', $validated['phone']);

        $hasAccount = false;

        if (strlen($digits) >= 8) {
            // Mêmes formes acceptées que partout ailleurs : E.164 saisi,
            // digits nus, préfixé automatiquement — et la normalisation
            // standard du backend (+229 + numéro national), pour qu'un
            // « 01 67 12 59 06 » saisi sans indicatif trouve bien le compte.
            $normalized = null;
            try {
                $normalized = app(OperatorDetectionService::class)->normalize($validated['phone']);
            } catch (\Throwable) {
                $normalized = null;
            }

            $candidates = array_unique(array_filter([
                $validated['phone'],
                $digits,
                '+' . $digits,
                $normalized,
            ]));

            foreach ($candidates as $candidate) {
                if (User::where('fripay_number', $candidate)->exists()
                    || User::where('phone_number', $candidate)->exists()) {
                    $hasAccount = true;
                    break;
                }
            }
        }

        return response()->json(['has_fripay_account' => $hasAccount]);
    }
}
