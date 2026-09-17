<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cahier §2 : table dediee au code de confirmation par email, separee
// de otp_codes (qui reste dediee au SMS/phone_number) pour ne pas
// toucher a un flux deja en production et parce que le format d'un
// email ne rentre pas dans phone_number varchar(20).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('email', 150);
            $table->text('code_hash');
            $table->smallInteger('attempts')->default(0);
            $table->boolean('consumed')->default(false);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['email', 'consumed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_codes');
    }
};
