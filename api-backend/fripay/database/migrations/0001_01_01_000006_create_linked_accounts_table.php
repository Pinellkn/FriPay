<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linked_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->unsignedSmallInteger('operator_id');
            $table->string('msisdn', 20);
            $table->string('alias_type', 20)->nullable();
            $table->string('alias_value', 64)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 20)->default('active');
            $table->foreign('operator_id')->references('id')->on('operators');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_accounts');
    }
};
