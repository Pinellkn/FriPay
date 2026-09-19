<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_qr_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offline_qr_code_id')->constrained('offline_qr_codes')->onDelete('cascade');
            $table->string('event_type', 30);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['offline_qr_code_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_qr_events');
    }
};
