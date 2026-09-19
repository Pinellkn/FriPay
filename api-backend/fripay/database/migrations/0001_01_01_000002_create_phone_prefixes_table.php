<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_prefixes', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('operator_id');
            $table->string('prefix', 10);
            $table->char('country_code', 2);
            $table->foreign('operator_id')->references('id')->on('operators')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_prefixes');
    }
};
