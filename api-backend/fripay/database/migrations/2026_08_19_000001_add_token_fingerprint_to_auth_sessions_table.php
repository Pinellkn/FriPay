<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->string('token_fingerprint', 64)->nullable()->after('refresh_token_hash');
        });

        // Index pour le lookup rapide par empreinte
        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->index(['token_fingerprint', 'revoked', 'expires_at'], 'auth_sessions_fingerprint_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('auth_sessions', function (Blueprint $table) {
            $table->dropIndex('auth_sessions_fingerprint_lookup_idx');
            $table->dropColumn('token_fingerprint');
        });
    }
};
