<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Virements INTERNES Fripay -> Fripay (par numéro 30 + 8 chiffres).
 *
 * Ces virements ne passent par aucun opérateur mobile money : la colonne
 * transactions.recipient_operator_id doit donc pouvoir être NULL. On crée
 * aussi l'opérateur interne FRIPAY pour les traces qui en ont besoin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedSmallInteger('recipient_operator_id')->nullable()->change();
        });

        // Opérateur interne Fripay (pour la traçabilité des virements
        // wallet-à-wallet et l'historique admin).
        DB::table('operators')->updateOrInsert(
            ['code' => 'FRIPAY'],
            ['name' => 'Fripay (interne)', 'country_code' => 'BJ', 'active' => true, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedSmallInteger('recipient_operator_id')->nullable(false)->change();
        });

        DB::table('operators')->where('code', 'FRIPAY')->delete();
    }
};
