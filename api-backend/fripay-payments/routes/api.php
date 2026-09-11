<?php

use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\ComplaintController;
use App\Http\Controllers\Api\MerchantQrController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FriPay Payments Service API Routes
| Prefix: /api/v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // --- Webhooks (pas de JWT, rate limité par IP) ---
    Route::middleware('throttle:webhook')->group(function () {
        Route::post('/webhooks/aggregator/{provider}', [WebhookController::class, 'handleAggregator']);
        Route::post('/webhooks/pispi', [WebhookController::class, 'handlePispi']);
        // MTN MoMo : callback natif (pas de signature HMAC)
        Route::post('/webhooks/mtn', [WebhookController::class, 'handleMtn']);
    });

    // --- Routes protégées ---
    Route::middleware('auth:sanctum')->group(function () {

        // Wallet (solde local Fripay)
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
        Route::post('/wallet/topup', [WalletController::class, 'topup']);
        Route::post('/wallet/withdraw', [WalletController::class, 'withdraw']);

        // Simulation de frais
        Route::post('/transfers/quote', [TransferController::class, 'quote']);

        // Initiation
        Route::post('/transfers', [TransferController::class, 'initiate']);

        // Suivi
        Route::get('/transfers/{transaction_id}', [TransferController::class, 'show']);
        Route::get('/transfers', [TransferController::class, 'index']);
        Route::post('/transfers/{transaction_id}/cancel', [TransferController::class, 'cancel']);

        // Plaintes (réclamations sur transactions / compte)
        Route::get('/complaints', [ComplaintController::class, 'index']);
        Route::post('/complaints', [ComplaintController::class, 'store']);
        Route::get('/complaints/{id}', [ComplaintController::class, 'show']);

        // Factures (paiement depuis le solde FriPay)
        Route::get('/bills/billers', [BillController::class, 'billers']);
        Route::post('/bills/pay', [BillController::class, 'pay']);
        Route::get('/bills', [BillController::class, 'index']);
        Route::get('/bills/{id}', [BillController::class, 'show']);
    });
});

// Health check
Route::get('/up', function () {
    return response()->json(['status' => 'ok', 'service' => 'FriPay Payments', 'version' => 'v1']);
});

// Alias sous /v1 — attendu par les clients/tests qui préfixent tout en v1
Route::get('/v1/up', function () {
    return response()->json(['status' => 'ok', 'service' => 'FriPay Payments', 'version' => 'v1']);
});

// ═══════════════════════════════════════════════════════════════════════
//  QR Codes hors-ligne (P2P)
// ═══════════════════════════════════════════════════════════════════════

Route::prefix('v1')->group(function () {

    // QR Verify — public, rate limité par IP (20 req/min)
    Route::post('/qr/verify', [\App\Http\Controllers\Api\OfflineQrController::class, 'verify'])
        ->middleware('throttle:qr-verify');

    // QR endpoints authentifiés — rate limités par user (60 req/min)
    Route::middleware(['auth:sanctum', 'throttle:qr-api'])->group(function () {
        Route::post('/qr/receive', [\App\Http\Controllers\Api\OfflineQrController::class, 'receive']);
        Route::post('/qr/redeem', [\App\Http\Controllers\Api\OfflineQrController::class, 'redeem']);
        Route::post('/qr/transfer', [\App\Http\Controllers\Api\OfflineQrController::class, 'transfer']);
        Route::post('/qr/revoke', [\App\Http\Controllers\Api\OfflineQrController::class, 'revoke']);
        Route::get('/qr/{uuid}/status', [\App\Http\Controllers\Api\OfflineQrController::class, 'status']);
        Route::get('/qr/mine/active', [\App\Http\Controllers\Api\OfflineQrController::class, 'mine']);
    });

    // QR Generate — authentifié + rate limité plus strictement (10 req/min, CPU-intensif)
    Route::post('/qr/generate', [\App\Http\Controllers\Api\OfflineQrController::class, 'generate'])
        ->middleware(['auth:sanctum', 'throttle:qr-generate']);
});

// ═══════════════════════════════════════════════════════════════════════
//  QR Paiements Marchand (CPM / MPM)
// ═══════════════════════════════════════════════════════════════════════

Route::prefix('v1')->group(function () {

    Route::middleware(['auth:sanctum', 'throttle:qr-api'])->group(function () {

        // ── MPM (Merchant Present Mode) ───────────────────────────────
        // Le marchand génère un QR, le client le scanne et paie.

        // Marchand génère un QR (static ou dynamic)
        Route::post('/qr/mpm/generate', [MerchantQrController::class, 'generateMpm']);

        // Client scanne le QR marchand
        Route::post('/qr/mpm/scan', [MerchantQrController::class, 'scanMpm']);

        // Client confirme le paiement (avec PIN)
        Route::post('/qr/mpm/pay', [MerchantQrController::class, 'payMpm']);

        // ── CPM (Customer Present Mode) ───────────────────────────────
        // Le client génère un QR, le marchand le scanne pour prélever.

        // Client génère un QR de paiement
        Route::post('/qr/cpm/generate', [MerchantQrController::class, 'generateCpm']);

        // Marchand scanne le QR client
        Route::post('/qr/cpm/scan', [MerchantQrController::class, 'scanCpm']);

        // Marchand encaisse (avec son PIN)
        Route::post('/qr/cpm/charge', [MerchantQrController::class, 'chargeCpm']);

        // ── Historique ────────────────────────────────────────────────

        // Historique des QR d'un marchand
        Route::get('/qr/merchant/history', [MerchantQrController::class, 'history']);
    });
});
