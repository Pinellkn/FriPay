<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FriPay Link — lien de paiement partageable.
 *
 * Un utilisateur FriPay crée une demande de paiement (montant verrouillé +
 * motif optionnel) et en partage l'URL publique. N'importe qui peut payer
 * via Mobile Money (FeexPay) sans compte FriPay ; à la confirmation, le
 * wallet du créateur est crédité via le ledger.
 *
 * Sécurité :
 * - `token` : identifiant PUBLIC non-devinable (40 caractères aléatoires),
 *   jamais l'ID séquentiel/UUID interne, qui reste en clé primaire pour les
 *   relations Eloquent.
 * - `amount` verrouillé côté serveur : le payeur ne le transmet jamais.
 * - Unicité de `provider_reference` (référence FeexPay) : idempotence du
 *   crédit en complément de l'index unique du ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Créateur du lien (bénéficiaire de l'argent).
            $table->uuid('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // Token PUBLIC non-devinable (ex. 40 chars base alphanumérique).
            $table->string('token', 64)->unique();

            // Montant verrouillé côté serveur — jamais modifiable par le payeur.
            $table->decimal('amount', 16, 2);
            $table->string('currency', 3)->default('XOF');

            // Motif facultatif, texte libre.
            $table->string('description', 255)->nullable();

            // created | paid | expired | cancelled
            $table->string('status', 20)->default('created')->index();

            // Référence FeexPay de la collecte en cours (idempotence webhook).
            $table->string('provider_reference')->nullable()->unique();

            // Date d'expiration (défaut : +48h à la création).
            $table->timestamp('expires_at')->nullable()->index();

            // Traçabilité du paiement.
            $table->string('payer_phone', 20)->nullable();
            $table->string('payer_operator', 10)->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
