<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La venta de un artículo de tienda (bien/vehículo/inmueble en
     * estado disponible_venta) a un cliente comprador, en una de tres
     * modalidades (forma_venta): contado, crédito o apartado. Independiente
     * del módulo Credito — ver App\Modules\Venta\Services\VentaService.
     */
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->constrained()->restrictOnDelete();

            $table->string('articulo_type');
            $table->unsignedBigInteger('articulo_id');

            // Solo informativo: el crédito cuya ejecución de garantía originó
            // este artículo en tienda, si se conoce.
            $table->foreignId('credito_origen_id')->nullable()->constrained('creditos_prendarios')->nullOnDelete();

            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('vendido_por')->constrained('users')->restrictOnDelete();

            $table->string('forma_venta'); // contado | credito | apartado
            $table->string('estado'); // activa | pagada | cancelada

            $table->decimal('precio_venta', 10, 2);
            $table->decimal('inicial', 10, 2)->default(0);

            // Solo forma_venta = credito.
            $table->decimal('interes', 5, 2)->nullable();
            $table->unsignedInteger('numero_cuotas')->nullable();

            // Solo forma_venta = apartado.
            $table->date('fecha_limite')->nullable();

            $table->decimal('saldo_pendiente', 10, 2)->default(0);

            $table->timestamp('pagada_at')->nullable();
            $table->timestamp('cancelada_at')->nullable();

            $table->timestamps();

            $table->index(['articulo_type', 'articulo_id']);
            $table->index('cliente_id');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
