<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adelantos y descuentos que se descuentan del sueldo mes a mes. Son
     * independientes de la planilla: se registran una vez (un adelanto, un
     * préstamo, una falta) y cada mes la planilla los va consumiendo hasta
     * saldarlos.
     *
     * `saldo` es lo que falta pagar — se descuenta al consumirlo, de modo que
     * un adelanto de 300 descontado en 3 meses queda con saldo 0 y deja de
     * aparecer en el reporte de meses siguientes.
     */
    public function up(): void
    {
        Schema::create('planilla_descuentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Adelanto | prestamo | falta | riesgo_caja | otro — el catálogo
            // vive en DescuentoTipo (ver app/Modules/Planilla/Tipos).
            $table->string('tipo');
            $table->string('concepto');

            $table->decimal('monto', 12, 2);
            // Cuánto se descuenta POR MES. Nulo = se descuenta el saldo entero
            // de una sola vez (un adelanto); con valor = se descuenta en
            // cuotas hasta saldarlo (un préstamo, una falta).
            $table->decimal('cuota', 12, 2)->nullable();
            $table->decimal('pagado', 12, 2)->default(0);
            $table->decimal('saldo', 12, 2);

            $table->date('fecha_registro');

            // Anulado deja de descontarse sin borrar el rastro histórico.
            $table->string('estado')->default('pendiente');

            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['empresa_id', 'user_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planilla_descuentos');
    }
};
