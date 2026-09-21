<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lie une écriture de ledger au QR « argent » concerné.
 *
 * wallet_ledger_entries.transaction_id est un UUID référencant la table
 * transactions — or offline_qr_codes.id est un bigint auto-incrémenté :
 * impossible de tracer le hold d'un QR via cette colonne. Cette colonne
 * dédiée permet au ledger de rester la source de vérité pour les
 * remboursements de QR (revoke / annulation parcours externe) : on ne
 * recrédite jamais que l'exact inverse du hold réellement enregistré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('offline_qr_code_id')->nullable()->after('transaction_id');
            $table->foreign('offline_qr_code_id')->references('id')->on('offline_qr_codes')->onDelete('cascade');
            $table->index(['offline_qr_code_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['offline_qr_code_id']);
            $table->dropIndex(['offline_qr_code_id', 'type']);
            $table->dropColumn('offline_qr_code_id');
        });
    }
};
