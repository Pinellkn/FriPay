<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_qr_codes', function (Blueprint $table) {
            $table->jsonb('metadata')->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('offline_qr_codes', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
