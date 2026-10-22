<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidencia obligatoria (video + GPS + fecha/hora) que un asesor debe
     * registrar antes de poder desembolsar un crédito diario — una fila por
     * crédito (volver a subir reemplaza la anterior, sin duplicar filas).
     * capturado_en es la hora que reporta el dispositivo (informativa);
     * recibido_en/confirmado_en las pone el servidor, que es la referencia
     * confiable para auditoría.
     */
    public function up(): void
    {
        Schema::create('credito_diario_desembolso_evidencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credito_id')->unique()->constrained('creditos_prendarios')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();

            $table->string('video_path');
            $table->string('video_mime_type')->nullable();
            $table->unsignedBigInteger('video_size_bytes')->nullable();

            $table->decimal('latitud', 10, 7);
            $table->decimal('longitud', 10, 7);
            $table->decimal('precision_metros', 8, 2)->nullable();

            $table->dateTime('capturado_en');
            $table->dateTime('recibido_en');
            $table->timestamp('confirmado_en')->nullable();
            $table->string('estado')->default('pendiente'); // pendiente | cargada | fallida

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_diario_desembolso_evidencias');
    }
};
