<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corridors', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('source_operator_id');
            $table->unsignedSmallInteger('destination_operator_id');
            $table->string('rail', 20);
            $table->string('aggregator_provider', 50)->nullable();
            $table->smallInteger('priority')->default(10);
            $table->string('fee_type', 20);
            $table->decimal('fee_value', 12, 4);
            $table->decimal('fee_cap', 14, 2)->nullable();
            $table->decimal('min_amount', 14, 2);
            $table->decimal('max_amount', 14, 2);
            $table->boolean('active')->default(true);
            $table->foreign('source_operator_id')->references('id')->on('operators');
            $table->foreign('destination_operator_id')->references('id')->on('operators');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corridors');
    }
};
