<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\LinkedAccountController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FriPay Users Service API Routes
| Prefix: /api/v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // --- Authentification (publique + rate limiting) ---
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/refresh-token', [AuthController::class, 'refreshToken']);
    });

    // --- Routes protégées ---
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Profil utilisateur
        Route::get('/users/me', [UserController::class, 'show']);
        Route::put('/users/me', [UserController::class, 'update']);
        Route::post('/users/me/pin', [UserController::class, 'setPin']);

        // Comptes mobile money liés
        Route::get('/users/me/accounts', [LinkedAccountController::class, 'index']);
        Route::post('/users/me/accounts', [LinkedAccountController::class, 'store'])
            ->middleware('idempotent');
        Route::delete('/users/me/accounts/{account_id}', [LinkedAccountController::class, 'destroy']);

        // Contacts
        Route::get('/users/me/contacts', [ContactController::class, 'index']);
        Route::post('/users/me/contacts', [ContactController::class, 'store']);
        Route::delete('/users/me/contacts/{contact_id}', [ContactController::class, 'destroy']);

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::put('/notifications/{notification_id}/read', [NotificationController::class, 'markAsRead']);
    });
});

// Health check
Route::get('/up', function () {
    return response()->json(['status' => 'ok', 'service' => 'FriPay Users', 'version' => 'v1']);
});
