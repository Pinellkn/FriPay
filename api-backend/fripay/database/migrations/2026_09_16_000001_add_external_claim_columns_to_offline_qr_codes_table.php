<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.e du cahier des charges — receveur sans compte Fripay.
 *
 * Ajoute au QR "argent" (offline_qr_codes) tout ce qu'il faut pour le
 * parcours "receveur externe" : numéro saisi par l'envoyeur, détection
 * de compte, code de validation à 5 chiffres, compteur de tentatives
 * (3 max), et le nécessaire pour le remboursement automatique de
 * l'envoyeur en cas d'échec définitif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_qr_codes', function (Blueprint $table) {
            $table->string('recipient_phone', 20)->nullable()->after('recipient_user_id');
            $table->boolean('has_recipient_account')->nullable()->after('recipient_phone');

            $table->string('external_validation_code', 5)->nullable()->after('has_recipient_account');
            $table->unsignedTinyInteger('external_attempts')->default(0)->after('external_validation_code');
            $table->unsignedTinyInteger('external_max_attempts')->default(3)->after('external_attempts');
            $table->string('external_payout_number', 20)->nullable()->after('external_max_attempts');
            $table->string('external_payout_network', 20)->nullable()->after('external_payout_number');
            $table->timestamp('external_claimed_at')->nullable()->after('external_payout_network');

            // Réservation/règlement réel de l'argent (le QR existant ne
            // débitait/créditait aucun wallet — juste un voucher signé).
            $table->timestamp('held_at')->nullable()->after('idempotency_key');
            $table->timestamp('settled_at')->nullable()->after('held_at');
            $table->timestamp('refunded_at')->nullable()->after('settled_at');

            $table->index('external_validation_code');
        });
    }

    public function down(): void
    {
        Schema::table('offline_qr_codes', function (Blueprint $table) {
            $table->dropColumn([
                'recipient_phone',
                'has_recipient_account',
                'external_validation_code',
                'external_attempts',
                'external_max_attempts',
                'external_payout_number',
                'external_payout_network',
                'external_claimed_at',
                'held_at',
                'settled_at',
                'refunded_at',
            ]);
        });
    }
};
