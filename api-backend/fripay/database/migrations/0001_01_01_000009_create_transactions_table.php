<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 40)->unique();
            $table->string('idempotency_key', 100)->unique();
            $table->foreignUuid('sender_user_id')->constrained('users');
            $table->foreignUuid('sender_account_id')->constrained('linked_accounts');
            $table->string('recipient_phone', 20);
            $table->unsignedSmallInteger('recipient_operator_id');
            $table->string('recipient_name', 150)->nullable();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('XOF');
            $table->decimal('fee_amount', 14, 2)->default(0);
            $table->decimal('total_debited', 14, 2);
            $table->string('rail_used', 20)->nullable();
            $table->string('aggregator_provider', 50)->nullable();
            $table->unsignedBigInteger('corridor_id')->nullable();
            $table->string('status', 20)->default('initiated');
            $table->text('failure_reason')->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->string('client_type_snapshot', 1)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('initiated_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreign('recipient_operator_id')->references('id')->on('operators');
            $table->foreign('corridor_id')->references('id')->on('corridors');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
