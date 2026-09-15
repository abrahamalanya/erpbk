<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite anular un cobro registrado por error mientras el ciclo de
     * caja donde se cobró sigue abierto (ver CreditoService::anularCobro()).
     * `credito_estado_anterior` es el estado del crédito ORIGINAL justo
     * antes de esta operación (activo/vencido) — se captura al registrar el
     * cobro para poder restaurarlo exacto al anular, sin adivinarlo después
     * a partir de fechas.
     */
    public function up(): void
    {
        Schema::table('cobros', function (Blueprint $table): void {
            $table->string('estado')->default('registrado')->after('operacion');
            $table->string('credito_estado_anterior')->nullable()->after('estado');
            // Referencia directa al ingreso de caja que generó este cobro
            // (registrarCobroEnCaja() lo crea siempre junto al Cobro) — sin
            // esto, anularCobro() no tendría forma confiable de encontrar
            // cuál movimiento borrar.
            $table->foreignId('caja_movimiento_id')->nullable()->after('caja_ciclo_id')->constrained('caja_movimientos')->nullOnDelete();
            $table->foreignId('anulado_por')->nullable()->after('registrado_por')->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable()->after('anulado_por');
            $table->string('motivo_anulacion')->nullable()->after('anulado_at');
        });
    }

    public function down(): void
    {
        Schema::table('cobros', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('anulado_por');
            $table->dropConstrainedForeignId('caja_movimiento_id');
            $table->dropColumn(['estado', 'credito_estado_anterior', 'anulado_at', 'motivo_anulacion']);
        });
    }
};
