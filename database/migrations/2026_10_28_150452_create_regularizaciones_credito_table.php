<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de "regularizaciones" de un crédito diario: condonar por
     * completo las cuotas más antiguas pendientes (capital+interés+mora)
     * sin que entre dinero por ese acto — una corrección administrativa de
     * administrador_general/sistemas, no un cobro. Por eso no hay
     * caja_ciclo_id/caja_movimiento_id ni relación con `cobros`.
     */
    public function up(): void
    {
        Schema::create('regularizaciones_credito', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->constrained()->restrictOnDelete();
            $table->foreignId('credito_id')->constrained('creditos_prendarios')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('numero_cuotas');
            $table->decimal('monto_condonado', 12, 2);
            $table->string('motivo');
            $table->timestamps();

            $table->index(['empresa_id', 'created_at']);
            $table->index('credito_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('regularizaciones_credito');
    }
};
