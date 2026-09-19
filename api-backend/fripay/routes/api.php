<?php

use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\CorridorController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\PhonePrefixController;
use App\Http\Controllers\Api\Admin\StaffController;
use App\Http\Controllers\Api\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\ComplaintController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ExternalClaimController;
use App\Http\Controllers\Api\LinkedAccountController;
use App\Http\Controllers\Api\MerchantQrController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OfflineQrController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TopupController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FriPay API Routes — application unique (fusion users + payments + admin)
| Prefix: /api/v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ═══════════════════════════════════════════════════════════════
    //  Authentification & compte (ex fripay-users)
    // ═══════════════════════════════════════════════════════════════

    Route::middleware('throttle:auth')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
        Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
        Route::post('/auth/resend-email-verification', [AuthController::class, 'resendEmailVerification']);
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/refresh-token', [AuthController::class, 'refreshToken']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/users/me', [UserController::class, 'show']);
        Route::put('/users/me', [UserController::class, 'update']);
        Route::post('/users/me/pin', [UserController::class, 'setPin']);

        Route::get('/users/me/accounts', [LinkedAccountController::class, 'index']);
        Route::post('/users/me/accounts', [LinkedAccountController::class, 'store'])
            ->middleware('idempotent');
        Route::delete('/users/me/accounts/{account_id}', [LinkedAccountController::class, 'destroy']);

        Route::get('/users/me/contacts', [ContactController::class, 'index']);
        Route::post('/users/me/contacts', [ContactController::class, 'store']);
        Route::delete('/users/me/contacts/{contact_id}', [ContactController::class, 'destroy']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::put('/notifications/{notification_id}/read', [NotificationController::class, 'markAsRead']);
    });

    // ═══════════════════════════════════════════════════════════════
    //  Webhooks (ex fripay-payments) — pas de JWT, rate limité par IP
    // ═══════════════════════════════════════════════════════════════

    Route::middleware('throttle:webhook')->group(function () {
        Route::post('/webhooks/aggregator/{provider}', [WebhookController::class, 'handleAggregator']);
        Route::post('/webhooks/pispi', [WebhookController::class, 'handlePispi']);
        Route::post('/webhooks/mtn', [WebhookController::class, 'handleMtn']);
        Route::post('/webhooks/feexpay', [WebhookController::class, 'handleFeexpay']);
    });

    // ═══════════════════════════════════════════════════════════════
    //  Wallet, transferts, factures, plaintes (ex fripay-payments)
    // ═══════════════════════════════════════════════════════════════

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
        Route::post('/wallet/topup', [WalletController::class, 'topup']);
        Route::post('/wallet/withdraw', [WalletController::class, 'withdraw']);

        // Recharge via l'agrégateur FeexPay
        Route::post('/wallet/topup/feexpay', [TopupController::class, 'initiate']);
        Route::get('/wallet/topup/feexpay', [TopupController::class, 'index']);
        Route::get('/wallet/topup/feexpay/{topupId}', [TopupController::class, 'status']);

        Route::post('/transfers/quote', [TransferController::class, 'quote']);
        Route::post('/transfers', [TransferController::class, 'initiate']);
        Route::get('/transfers/{transaction_id}', [TransferController::class, 'show']);
        Route::get('/transfers', [TransferController::class, 'index']);
        Route::post('/transfers/{transaction_id}/cancel', [TransferController::class, 'cancel']);

        Route::get('/complaints', [ComplaintController::class, 'index']);
        Route::post('/complaints', [ComplaintController::class, 'store']);
        Route::get('/complaints/{id}', [ComplaintController::class, 'show']);

        Route::get('/bills/billers', [BillController::class, 'billers']);
        Route::post('/bills/pay', [BillController::class, 'pay']);
        Route::get('/bills', [BillController::class, 'index']);
        Route::get('/bills/{id}', [BillController::class, 'show']);

        // §8 - Interface technique : préfixes réseau
        Route::get('/network/prefixes', [SystemController::class, 'prefixes']);
    });

    // ═══════════════════════════════════════════════════════════════
    //  QR Codes hors-ligne (P2P) — ex fripay-payments
    // ═══════════════════════════════════════════════════════════════

    Route::post('/qr/verify', [OfflineQrController::class, 'verify'])
        ->middleware('throttle:qr-verify');

    Route::middleware(['auth:sanctum', 'throttle:qr-api'])->group(function () {
        Route::post('/qr/receive', [OfflineQrController::class, 'receive']);
        Route::post('/qr/redeem', [OfflineQrController::class, 'redeem']);
        Route::post('/qr/transfer', [OfflineQrController::class, 'transfer']);
        Route::post('/qr/revoke', [OfflineQrController::class, 'revoke']);
        Route::get('/qr/{uuid}/status', [OfflineQrController::class, 'status']);
        Route::get('/qr/mine/active', [OfflineQrController::class, 'mine']);
    });

    Route::post('/qr/generate', [OfflineQrController::class, 'generate'])
        ->middleware(['auth:sanctum', 'throttle:qr-generate']);

    // §6.e — Parcours receveur externe (page web publique, pas de compte)
    Route::middleware('throttle:qr-external-claim')->group(function () {
        Route::get('/qr/external/{uuid}', [ExternalClaimController::class, 'lookup']);
        Route::post('/qr/external/{uuid}/claim', [ExternalClaimController::class, 'claim']);
    });

    // QR Paiements Marchand (CPM / MPM)
    Route::middleware(['auth:sanctum', 'throttle:qr-api'])->group(function () {
        Route::post('/qr/mpm/generate', [MerchantQrController::class, 'generateMpm']);
        Route::post('/qr/mpm/scan', [MerchantQrController::class, 'scanMpm']);
        Route::post('/qr/mpm/pay', [MerchantQrController::class, 'payMpm']);
        Route::post('/qr/cpm/generate', [MerchantQrController::class, 'generateCpm']);
        Route::post('/qr/cpm/scan', [MerchantQrController::class, 'scanCpm']);
        Route::post('/qr/cpm/charge', [MerchantQrController::class, 'chargeCpm']);
        Route::get('/qr/merchant/history', [MerchantQrController::class, 'history']);
    });

    // ═══════════════════════════════════════════════════════════════
    //  Back-office admin (ex fripay-admin)
    // ═══════════════════════════════════════════════════════════════

    Route::post('/admin/auth/login', [AdminAuthController::class, 'login']);

    Route::middleware(['auth:staff'])->group(function () {

        Route::get('/admin/users', [AdminUserController::class, 'index'])
            ->middleware('admin:users.read');
        Route::get('/admin/users/{user_id}', [AdminUserController::class, 'show'])
            ->middleware('admin:users.read');
        Route::put('/admin/users/{user_id}/status', [AdminUserController::class, 'updateStatus'])
            ->middleware('admin:users.block');

        Route::get('/admin/transactions', [AdminTransactionController::class, 'index'])
            ->middleware('admin:transactions.read');
        Route::get('/admin/transactions/{transaction_id}', [AdminTransactionController::class, 'show'])
            ->middleware('admin:transactions.read');
        Route::post('/admin/transactions/{transaction_id}/retry', [AdminTransactionController::class, 'retry'])
            ->middleware('admin:transactions.retry');

        Route::get('/admin/corridors', [CorridorController::class, 'index'])
            ->middleware('admin:corridors.read');
        Route::post('/admin/corridors', [CorridorController::class, 'store'])
            ->middleware('admin:corridors.write');
        Route::put('/admin/corridors/{corridor_id}', [CorridorController::class, 'update'])
            ->middleware('admin:corridors.write');

        Route::get('/admin/phone-prefixes', [PhonePrefixController::class, 'index'])
            ->middleware('admin:corridors.read');
        Route::post('/admin/phone-prefixes', [PhonePrefixController::class, 'store'])
            ->middleware('admin:corridors.write');
        Route::delete('/admin/phone-prefixes/{prefixId}', [PhonePrefixController::class, 'destroy'])
            ->middleware('admin:corridors.write');

        Route::get('/admin/dashboard/kpis', [DashboardController::class, 'kpis'])
            ->middleware('admin:dashboard.read');

        Route::get('/admin/staff', [StaffController::class, 'index'])
            ->middleware('admin:staff.read');
        Route::post('/admin/staff', [StaffController::class, 'store'])
            ->middleware('admin:staff.write');
        Route::put('/admin/staff/{staffId}/role', [StaffController::class, 'updateRole'])
            ->middleware('admin:staff.write');
    });

    // Alias sous /v1 (attendu par certains clients qui préfixent tout en v1)
    Route::get('/up', function () {
        return response()->json(['status' => 'ok', 'service' => 'FriPay', 'version' => 'v1']);
    });
});

// Health check racine
Route::get('/up', function () {
    return response()->json(['status' => 'ok', 'service' => 'FriPay', 'version' => 'v1']);
});
