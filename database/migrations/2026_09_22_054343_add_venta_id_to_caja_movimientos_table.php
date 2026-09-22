<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enlaza un movimiento de caja con la venta que lo originó (inicial,
     * cuota, abono o pago de contado) — mirror de la columna credito_id que
     * ya tiene esta tabla.
     */
    public function up(): void
    {
        Schema::table('caja_movimientos', function (Blueprint $table): void {
            $table->foreignId('venta_id')->nullable()->after('credito_id')->constrained('ventas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('caja_movimientos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('venta_id');
        });
    }
};
