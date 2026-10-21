<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial append-only de ubicaciones GPS de asesores: a diferencia de
     * `ubicaciones_asesores` (última posición, se sobrescribe), aquí se
     * inserta una fila por cada ping válido — es la base del reporte de
     * recorridos. empresa_id/agencia_id se copian del asesor al registrar,
     * igual que en `ubicaciones_asesores`.
     */
    public function up(): void
    {
        Schema::create('ubicacion_asesor_historial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('latitud', 10, 7);
            $table->decimal('longitud', 10, 7);
            $table->decimal('precision_metros', 8, 2)->nullable();
            $table->decimal('velocidad_ms', 8, 2)->nullable();
            $table->timestamp('capturado_en');
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'capturado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicacion_asesor_historial');
    }
};
