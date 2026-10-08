<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasas de aportes y contribuciones con las que se cierra cada línea de
     * planilla, por empresa/agencia (agencia_id nulo = fila default de la
     * empresa) — mismo criterio que configuraciones_venta.
     *
     * Los defaults son los del régimen peruano (ONP y AFP como regímenes
     * alternantes de pensión), guardados como porcentaje porque así los
     * mantiene el usuario: 3.00 = 3%. El servicio calcula sobre la base
     * imponible de cada línea (ver PlanillaService::baseImponible()).
     */
    public function up(): void
    {
        Schema::create('configuraciones_planilla', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();

            // Aportes del trabajador (se restan del sueldo).
            $table->decimal('onp_trabajador', 5, 2)->default(3.00);
            $table->decimal('afp_trabajador', 5, 2)->default(10.00);
            $table->decimal('essalud_trabajador', 5, 2)->default(4.00);

            // Aportes del empleador (no se restan del sueldo; son costo de la
            // empresa, pero se reportan en la planilla).
            $table->decimal('essalud_empleador', 5, 2)->default(5.00);
            $table->decimal('cts_empleador', 5, 2)->default(8.33);

            $table->timestamps();

            $table->unique(['empresa_id', 'agencia_id'], 'configuraciones_planilla_empresa_agencia_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_planilla');
    }
};
