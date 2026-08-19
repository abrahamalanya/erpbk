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
        Schema::create('bien_credito_prendario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credito_prendario_id')->constrained('creditos_prendarios')->cascadeOnDelete();
            $table->foreignId('bien_id')->constrained('bienes')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['credito_prendario_id', 'bien_id'], 'bien_credito_prendario_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bien_credito_prendario');
    }
};
