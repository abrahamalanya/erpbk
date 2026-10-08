<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Una línea de planilla por trabajador. Los importes del trabajador
     * (descuentos, tributos, neto) y del empleador (essalud + CTS) se
     * calculan y se persisten por línea al confirmar, de modo que el reporte
     * histórico no cambia si mañana editan las tasas configuradas.
     *
     * El snapshot de identidad (tipo_documento, numero_documento, apellidos,
     * nombres, situacion) se copia del usuario al crear la línea porque el
     * reporte lo imprime y un usuario puede cambiar de nombre o documento
     * después; el reporte de un mes pagado debe seguir mostrando lo que se
     * declaró ese mes.
     */
    public function up(): void
    {
        Schema::create('planilla_trabajadores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planilla_id')->constrained()->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Snapshot de identidad del trabajador (ver cabecera).
            $table->string('tipo_documento', 20);
            $table->string('numero_documento', 20);
            $table->string('apellidos');
            $table->string('nombres');
            $table->string('situacion')->default('activo');

            // Ingresos: sueldo base + bonificaciones, ya sumados por el
            // usuario. Es la base imponible de los aportes.
            $table->decimal('ingresos', 12, 2)->default(0);

            // Descuentos devengado (lo que se le descuenta en total a lo largo
            // del préstamo/adelanto) y pagado (lo que se le descuenta ESTE
            // mes). Ambos aparecen por separado en el reporte.
            $table->decimal('descuentos_devengado', 12, 2)->default(0);
            $table->decimal('descuentos_pagado', 12, 2)->default(0);

            // Desglose del aporte del trabajador, para que el reporte pueda
            // separar ONP/AFP/Essalud sin recalcular. tributaries_trabajador
            // es la suma de los tres.
            $table->decimal('onp', 12, 2)->default(0);
            $table->decimal('afp', 12, 2)->default(0);
            $table->decimal('essalud_trabajador', 12, 2)->default(0);
            $table->decimal('tributos_trabajador', 12, 2)->default(0);

            // Lo que la empresa aporta: Essalud + CTS.
            $table->decimal('essalud_empleador', 12, 2)->default(0);
            $table->decimal('cts', 12, 2)->default(0);
            $table->decimal('aportes_empleador', 12, 2)->default(0);

            // Lo que se le paga al trabajador: ingresos - descuentos_pagado -
            // tributos_trabajador.
            $table->decimal('neto', 12, 2)->default(0);

            $table->timestamps();

            // Un trabajador, una línea por planilla.
            $table->unique(['planilla_id', 'user_id']);
            $table->index(['empresa_id', 'planilla_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planilla_trabajadores');
    }
};
