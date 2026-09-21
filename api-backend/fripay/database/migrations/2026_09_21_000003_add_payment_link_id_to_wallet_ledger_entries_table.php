<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lie une écriture de ledger au FriPay Link concerné.
 *
 * wallet_ledger_entries.transaction_id est un UUID référencant la table
 * transactions (FK stricte) — il ne peut donc pas porter l'ID d'un lien de
 * paiement. Une colonne dédiée (même pattern que offline_qr_code_id) permet
 * au ledger de rester la source de vérité pour les mouvements des liens :
 * crédit du créateur à la confirmation du paiement, traçabilité complète.
 *
 * L'unicité (payment_link_id + type=credit) n'est pas contrainte en base :
 * l'idempotence est assurée par le verrou pessimiste sur le lien dans
 * PaymentLinkService::markLinkPaid() (statut testé sous lockForUpdate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->uuid('payment_link_id')->nullable()->after('offline_qr_code_id');
            $table->index(['payment_link_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['payment_link_id', 'type']);
            $table->dropColumn('payment_link_id');
        });
    }
};
