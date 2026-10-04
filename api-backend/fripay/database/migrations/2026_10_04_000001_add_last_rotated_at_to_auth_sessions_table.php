<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table) {
            // Moment où la session a été révoquée par une rotation de
            // refresh token. Null = session jamais tournée. Sert de départ
            // à la grace period qui tolère le rejeu d'un ancien refresh
            // token dont la réponse (nouvelle paire) a été perdue par le
            // client (timeout réseau, app tuée, crash) — sinon la session
            // devient définitivement irrécupérable (401 + refresh mort).
            $table->timestamp('last_rotated_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->dropColumn('last_rotated_at');
        });
    }
};
