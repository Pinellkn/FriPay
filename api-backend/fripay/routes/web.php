<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// §8 - Statut technique consulté par l'interface technique de l'app mobile
// (hors /api/v1). Remplace le /__gateway/status de l'ancienne architecture
// 3 microservices : ici une seule application, le "circuit" reflète juste
// la santé de la base. La clé de debug n'est vérifiée QUE si
// FRIPAY_GATEWAY_DEBUG_KEY est définie dans .env.
Route::get('/__gateway/status', function (Illuminate\Http\Request $request) {
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

// Liens stores de l'application FriPay — tant que l'app n'est pas publiée,
// on pointe vers les pages génériques des stores (surchargeables via .env :
// FRIPAY_PLAY_STORE_URL / FRIPAY_APP_STORE_URL).
$playStoreUrl = env('FRIPAY_PLAY_STORE_URL', 'https://play.google.com/store/apps');
$appStoreUrl = env('FRIPAY_APP_STORE_URL', 'https://apps.apple.com/fr/app/apple-store');
// Schéma de deep link de l'app (web1 « Payer via FriPay » → ouvrir l'appli).
$deepLinkScheme = env('FRIPAY_DEEP_LINK_SCHEME', 'fripay');

// §6.e du cahier des charges : page web publique pour le receveur d'un QR
// "argent" (web2). Un scanner externe ouvre directement cette page.
// Deux blocs : « Télécharger l'application » (stores) et « Recevoir l'argent
// sur mon compte mobile » (formulaire numéro + code de vérification, 3
// tentatives max — au 3ᵉ échec l'argent retourne à l'expéditeur).
// La page consomme GET/POST /api/v1/qr/external/{uuid}(/claim) en JS.
Route::get('/claim/{uuid}', function (string $uuid) use ($playStoreUrl, $appStoreUrl) {
    return view('claim.show', [
        'uuid' => $uuid,
        'playStoreUrl' => $playStoreUrl,
        'appStoreUrl' => $appStoreUrl,
    ]);
})->name('claim.show');

// FriPay Link : page web publique de PAIEMENT d'un lien partageable (web1).
// Deux blocs : « Payer via FriPay » (vérifie si le numéro possède un compte
// → ouvre l'appli via deep link, sinon renvoie vers les stores) et « Payer
// via mon compte mobile » (formulaire opérateur + numéro, push de validation).
// La page consomme GET/POST /api/v1/payment-links/{token}(/pay)(/status) en JS,
// plus GET /api/v1/public/phone-check pour le bloc « Payer via FriPay ».
Route::get('/pay/{token}', function (string $token) use ($playStoreUrl, $appStoreUrl, $deepLinkScheme) {
    return view('pay.link', [
        'token' => $token,
        'playStoreUrl' => $playStoreUrl,
        'appStoreUrl' => $appStoreUrl,
        'deepLinkScheme' => $deepLinkScheme,
    ]);
})->name('pay.link');
