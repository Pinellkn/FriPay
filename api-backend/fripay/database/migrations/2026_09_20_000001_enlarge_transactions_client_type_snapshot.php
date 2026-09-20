<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FIX : la migration d'origine créait la colonne en VARCHAR(1) — on ne
// pouvait donc y stocker NI 'qr_payment' (10 caractères, écrit par
// MerchantQrController pour tout paiement de QR marchand) NI le
// client_type de l'utilisateur ('web' / 'mobile', écrit par
// TransferService). Résultat : tout paiement de QR marchand et tout
// transfert plantait en SQLSTATE[22001] « Data too long for column
// 'client_type_snapshot' ». On passe à VARCHAR(32), largement suffisant.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('client_type_snapshot', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('client_type_snapshot', 1)->nullable()->change();
        });
    }
};
