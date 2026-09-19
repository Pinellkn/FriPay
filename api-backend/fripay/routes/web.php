<?php

use Illuminate\Support\Facades\Route;

// Routes Web — la majorité du service est API only, sauf la page ci-dessous.

// §6.e du cahier des charges : page web publique pour le receveur d'un QR
// "argent" qui n'a pas de compte Fripay. Un scanner externe redirige ici.
// La page consomme GET/POST /api/v1/qr/external/{uuid}(/claim) en JS.
Route::get('/claim/{uuid}', function (string $uuid) {
    return view('claim.show', ['uuid' => $uuid]);
})->name('claim.show');
