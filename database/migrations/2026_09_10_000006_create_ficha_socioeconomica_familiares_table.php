<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sección 3 de la ficha: familiares con quienes vive el cliente. Lista
     * que se reemplaza completa en cada guardado de la ficha.
     */
    public function up(): void
    {
        Schema::create('ficha_socioeconomica_familiares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ficha_socioeconomica_id')->constrained('fichas_socioeconomicas')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();

            $table->string('nombres');
            $table->unsignedInteger('edad')->nullable();
            $table->string('parentesco')->nullable();
            $table->string('estado_civil')->nullable();
            $table->string('ocupacion')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ficha_socioeconomica_familiares');
    }
};
