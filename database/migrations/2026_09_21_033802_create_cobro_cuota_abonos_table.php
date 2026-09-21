<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Detalle de qué aplicó cada cobro sobre cada cuota de un crédito diario
     * (ver CobroCuotaAbono) — base del pago parcial/adelanto y de su
     * anulación.
     */
    public function up(): void
    {
        Schema::create('cobro_cuota_abonos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cobro_id')->constrained('cobros')->cascadeOnDelete();
            $table->foreignId('cuota_credito_id')->constrained('cuotas_credito_prendario')->cascadeOnDelete();
            $table->decimal('monto_mora', 10, 2)->default(0);
            $table->decimal('monto_cuota', 10, 2)->default(0);
            $table->boolean('completa')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_cuota_abonos');
    }
};
