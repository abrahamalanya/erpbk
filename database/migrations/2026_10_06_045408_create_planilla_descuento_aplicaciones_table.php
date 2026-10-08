<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Qué descuento se aplicó a qué línea, y por cuánto. Sin este rastro, reabrir
     * una planilla (ver PlanillaService::reabrir()) solo podría "devolver" un
     * importe a ciegas; con él la reversión es exacta y auditable: se sabe qué
     * descuento se había tocado y se le devuelve exactamente ese monto a su
     * `saldo`.
     *
     * Las filas se crean al cerrar la planilla y se eliminan al reabrirla.
     */
    public function up(): void
    {
        Schema::create('planilla_descuento_aplicaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planilla_id')->constrained()->cascadeOnDelete();
            // Se nombra la tabla a mano: `constrained()` deduciría `planilla_trabajadors`
            // del nombre de la columna y no la encontraría.
            $table->foreignId('planilla_trabajador_id')->constrained('planilla_trabajadores')->cascadeOnDelete();
            $table->foreignId('planilla_descuento_id')->constrained()->cascadeOnDelete();

            $table->decimal('monto', 12, 2);

            $table->timestamps();

            // Un descuento no se toca dos veces por línea (el consumo es
            // oldest-first y termina al agotar el importe).
            $table->unique(['planilla_trabajador_id', 'planilla_descuento_id'], 'planilla_aplicacion_unica');
            $table->index(['planilla_id', 'planilla_descuento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planilla_descuento_aplicaciones');
    }
};
