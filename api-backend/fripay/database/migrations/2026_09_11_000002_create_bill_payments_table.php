<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 40)->unique();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('biller_id')->constrained('billers');
            $table->string('subscriber_reference', 100);
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('paid');
            // paid | failed
            $table->foreignUuid('wallet_ledger_entry_id')->nullable()
                ->constrained('wallet_ledger_entries');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_payments');
    }
};
