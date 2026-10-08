<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cabecera de la planilla de un periodo. Una fila por (empresa, agencia,
     * periodo): el mes que se liquida y en qué agencia, con sus totales ya
     * sumados para no tener que recorrer las líneas en cada pantalla.
     *
     * `estado` recorre borrador → confirmada → pagada. Los totales se congelan
     * al confirmar y `cerrada_at`/`cerrada_por` marcan quién la liquidó, para
     * que un mes ya pagado no se pueda reeditar a espaldas delSnapshot contable.
     */
    public function up(): void
    {
        Schema::create('planillas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();

            // Periodo liquidado, 'YYYY-MM'.
            $table->char('periodo', 7);
            $table->date('fecha_pago');

            $table->string('estado')->default('borrador');

            // Totales de la planilla (string de在各 líneas, no se recalculan en
            // cada request). LosSETTLE cálculo vive en PlanillaService.
            $table->decimal('total_ingresos', 12, 2)->default(0);
            $table->decimal('total_descuentos', 12, 2)->default(0);
            $table->decimal('total_tributos_trabajador', 12, 2)->default(0);
            $table->decimal('total_neto', 12, 2)->default(0);
            $table->decimal('total_aportes_empleador', 12, 2)->default(0);

            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cerrada_at')->nullable();

            $table->timestamps();

            // Una planilla por agencia y mes. Como agencia_id admite NULL, el
            // índice único no alcanza para planillas de empresa completa, así
            // que se cubre ese caso con un índice sobre (empresa_id, periodo)
            // solo cuando agencia_id es NULL no es posible en MySQL portable:
            // se resuelve en PlanillaService::crear(), que rechaza el duplicado
            // explícitamente para no depender del motor.
            $table->index(['empresa_id', 'periodo']);
            $table->index(['agencia_id', 'periodo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planillas');
    }
};
