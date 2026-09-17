<?php

use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\CorridorController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\PhonePrefixController;
use App\Http\Controllers\Api\Admin\StaffController;
use App\Http\Controllers\Api\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FriPay Admin Service API Routes
| Prefix: /api/v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // --- Auth back-office (publique) ---
    Route::post('/admin/auth/login', [AdminAuthController::class, 'login']);

    // --- Routes protégées (auth:staff + RBAC individuel sur chaque route) ---
    Route::middleware(['auth:staff'])->group(function () {

        // Utilisateurs
        Route::get('/admin/users', [AdminUserController::class, 'index'])
            ->middleware('admin:users.read');
        Route::get('/admin/users/{user_id}', [AdminUserController::class, 'show'])
            ->middleware('admin:users.read');
        Route::put('/admin/users/{user_id}/status', [AdminUserController::class, 'updateStatus'])
            ->middleware('admin:users.block');

        // Transactions
        Route::get('/admin/transactions', [AdminTransactionController::class, 'index'])
            ->middleware('admin:transactions.read');
        Route::get('/admin/transactions/{transaction_id}', [AdminTransactionController::class, 'show'])
            ->middleware('admin:transactions.read');
        Route::post('/admin/transactions/{transaction_id}/retry', [AdminTransactionController::class, 'retry'])
            ->middleware('admin:transactions.retry');

        // Corridors
        Route::get('/admin/corridors', [CorridorController::class, 'index'])
            ->middleware('admin:corridors.read');
        Route::post('/admin/corridors', [CorridorController::class, 'store'])
            ->middleware('admin:corridors.write');
        Route::put('/admin/corridors/{corridor_id}', [CorridorController::class, 'update'])
            ->middleware('admin:corridors.write');

        // §8 — Interface technique : gestion des préfixes réseau
        // (MTN/Moov/Celtiis). La détection d'opérateur (fripay-common) lit
        // la même table phone_prefixes : effet immédiat, sans redéploiement.
        Route::get('/admin/phone-prefixes', [PhonePrefixController::class, 'index'])
            ->middleware('admin:corridors.read');
        Route::post('/admin/phone-prefixes', [PhonePrefixController::class, 'store'])
            ->middleware('admin:corridors.write');
        Route::delete('/admin/phone-prefixes/{prefixId}', [PhonePrefixController::class, 'destroy'])
            ->middleware('admin:corridors.write');

        // Dashboard
        Route::get('/admin/dashboard/kpis', [DashboardController::class, 'kpis'])
            ->middleware('admin:dashboard.read');

        // Staff
        Route::get('/admin/staff', [StaffController::class, 'index'])
            ->middleware('admin:staff.read');
        Route::post('/admin/staff', [StaffController::class, 'store'])
            ->middleware('admin:staff.write');
        Route::put('/admin/staff/{staffId}/role', [StaffController::class, 'updateRole'])
            ->middleware('admin:staff.write');
    });
});

// Health check
Route::get('/up', function () {
    return response()->json(['status' => 'ok', 'service' => 'FriPay Admin', 'version' => 'v1']);
});
