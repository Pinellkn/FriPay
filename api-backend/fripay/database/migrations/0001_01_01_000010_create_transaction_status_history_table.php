<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('transaction_id')->constrained('transactions')->onDelete('cascade');
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->string('source', 50)->default('system');
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_status_history');
    }
};
