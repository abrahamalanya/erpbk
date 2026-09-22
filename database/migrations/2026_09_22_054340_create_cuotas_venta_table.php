<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cronograma de una venta a crédito (forma_venta = credito). No aplica a
     * contado ni apartado — el apartado se cubre con abonos libres, ver
     * pagos_venta.
     */
    public function up(): void
    {
        Schema::create('cuotas_venta', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('numero_cuota');
            $table->date('fecha_vencimiento');
            $table->decimal('monto_capital', 10, 2);
            $table->decimal('monto_interes', 10, 2);
            $table->decimal('monto_total', 10, 2);
            $table->decimal('monto_abonado', 10, 2)->default(0);
            $table->string('estado')->default('pendiente'); // pendiente | pagada

            $table->timestamps();

            $table->index('venta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuotas_venta');
    }
};
