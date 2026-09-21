<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// §8 - Statut technique consulté par l'interface technique de l'app mobile
// (hors /api/v1). Remplace le /__gateway/status de l'ancienne architecture
// 3 microservices : ici une seule application, le "circuit" reflète juste
// la santé de la base. La clé de debug n'est vérifiée QUE si
// FRIPAY_GATEWAY_DEBUG_KEY est définie dans .env.
Route::get('/__gateway/status', function (Request $request) {
    $expected = env('FRIPAY_GATEWAY_DEBUG_KEY');
    if ($expected && $request->header('X-Fripay-Debug-Key') !== $expected) {
        return response()->json(['message' => 'Clé de debug invalide.'], 401);
    }

    $dbOk = false;
    try {
        DB::select('SELECT 1');
        $dbOk = true;
    } catch (\Throwable) {
        $dbOk = false;
    }

    return response()->json([
        'services' => [
            'fripay-app' => [
                'name' => 'FriPay (app unique)',
                'base_url' => $request->getSchemeAndHttpHost(),
                'circuit' => $dbOk ? 'closed' : 'open',
                'failures' => $dbOk ? 0 : 1,
            ],
        ],
    ]);
});

// Routes Web — la majorité du service est API only, sauf les pages ci-dessus.

// §6.e du cahier des charges : page web publique pour le receveur d'un QR
// "argent" qui n'a pas de compte Fripay. Un scanner externe redirige ici.
// La page consomme GET/POST /api/v1/qr/external/{uuid}(/claim) en JS.
Route::get('/claim/{uuid}', function (string $uuid) {
    return view('claim.show', ['uuid' => $uuid]);
})->name('claim.show');

// FriPay Link : page web publique de PAIEMENT d'un lien partageable.
// Le payeur externe (sans compte FriPay) y voit le montant verrouillé et le
// créateur (nom partiel), choisit son opérateur et paie via FeexPay. La page
// consomme GET/POST /api/v1/payment-links/{token}(/pay)(/status) en JS.
Route::get('/pay/{token}', function (string $token) {
    return view('pay.link', ['token' => $token]);
})->name('pay.link');
