<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_qr_codes', function (Blueprint $table) {
            // Mode de paiement : cpm (Customer Present) ou mpm (Merchant Present)
            $table->string('qr_mode', 10)->default('mpm')->after('status');

            // Type de QR : static (montant saisi par le payeur) ou dynamic (montant pré-rempli)
            $table->string('qr_type', 10)->default('dynamic')->after('qr_mode');

            // Utilisateur marchand propriétaire du QR (null = P2P classique)
            $table->foreignUuid('merchant_user_id')->nullable()->after('recipient_user_id');

            // Description du paiement (ex: "Achat boutique #1234")
            $table->text('description')->nullable()->after('merchant_user_id');

            // QR à usage unique (détruit après premier paiement)
            $table->boolean('single_use')->default(false);

            // Nombre de fois que le QR a été utilisé
            $table->unsignedBigInteger('use_count')->default(0);

            // Index pour les recherches marchand
            $table->index(['merchant_user_id', 'status']);
            $table->index(['qr_mode', 'qr_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('offline_qr_codes', function (Blueprint $table) {
            $table->dropIndex(['merchant_user_id', 'status']);
            $table->dropIndex(['qr_mode', 'qr_type', 'status']);
            $table->dropColumn([
                'qr_mode',
                'qr_type',
                'merchant_user_id',
                'description',
                'single_use',
                'use_count',
            ]);
        });
    }
};
