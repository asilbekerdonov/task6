<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('circuit_wires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circuit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_component_id')->constrained('circuit_components')->cascadeOnDelete();
            $table->unsignedTinyInteger('from_pin')->default(0);
            $table->foreignId('to_component_id')->constrained('circuit_components')->cascadeOnDelete();
            $table->unsignedTinyInteger('to_pin')->default(0);
            $table->timestamps();
            $table->unique(['to_component_id', 'to_pin']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('circuit_wires');
    }
};
