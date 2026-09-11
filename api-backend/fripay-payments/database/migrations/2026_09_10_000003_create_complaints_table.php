<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 40)->unique();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('reason', 30);
            // wrong_transfer | not_received | duplicate_charge | account_issue | other
            $table->string('subject', 150);
            $table->text('description');
            $table->string('status', 20)->default('open');
            // open | in_progress | resolved | rejected
            $table->foreignUuid('linked_transaction_id')->nullable()->constrained('transactions');
            $table->boolean('refund_requested')->default(false);
            $table->string('refund_status', 20)->default('not_applicable');
            // not_applicable | requested | approved | rejected | processed
            $table->decimal('refund_amount', 14, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
    }
};
