<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada pago recibido sobre una venta: el pago único de un contado, el
     * inicial de un crédito/apartado, el pago de una cuota de crédito, o un
     * abono libre de un apartado. Mueve caja (mirror de Cobro en el módulo
     * Credito) — el movimiento de caja sigue siendo la fuente contable.
     */
    public function up(): void
    {
        Schema::create('pagos_venta', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->restrictOnDelete();
            $table->foreignId('cuota_venta_id')->nullable()->constrained('cuotas_venta')->nullOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('caja_ciclo_id')->constrained('caja_ciclos')->restrictOnDelete();
            $table->foreignId('caja_movimiento_id')->nullable()->constrained('caja_movimientos')->nullOnDelete();
            $table->foreignId('registrado_por')->constrained('users')->restrictOnDelete();

            $table->string('tipo'); // contado | inicial | cuota | abono
            $table->decimal('monto', 10, 2);
            $table->string('medio'); // efectivo | yape | plin | transferencia

            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion')->nullable();

            $table->timestamps();

            $table->index('venta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_venta');
    }
};
