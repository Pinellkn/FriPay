<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recharges du wallet (cash-in) via l'agrégateur FeexPay.
 *
 * Le crédit du wallet passe par WalletService::credit() avec
 * transaction_id = wallet_topups.id. La table wallet_ledger_entries porte
 * déjà un index unique sur transaction_id — garantit l'idempotence du
 * crédit (un webhook FeexPay rejoué ne créditera jamais deux fois).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('XOF');
            $table->string('operator_code', 20);          // MTN | MOOV (collecte FeexPay)
            $table->string('phone_number', 20);           // MSISDN débité (E.164)
            $table->string('status', 20)->default('pending'); // pending|processing|completed|failed
            $table->string('reference', 40)->unique();    // référence métier FriPay (idempotence)
            $table->string('provider_reference', 100)->nullable()->unique(); // référence FeexPay
            $table->text('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_topups');
    }
};
