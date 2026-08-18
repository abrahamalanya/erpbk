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
        Schema::create('documentos_credito_prendario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credito_id')->constrained('creditos_prendarios')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();

            $table->string('tipo');
            $table->string('pdf_path');

            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generado_at');
            $table->timestamp('impreso_at')->nullable();
            $table->timestamp('firmado_at')->nullable();

            $table->timestamps();

            $table->index('credito_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documentos_credito_prendario');
    }
};
