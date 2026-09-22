<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documentos generados de una venta (voucher, contrato_credito,
     * contrato_apartado, compra_venta, notarial). Mirror de
     * documentos_credito_prendario.
     */
    public function up(): void
    {
        Schema::create('documentos_venta', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();

            $table->string('tipo');
            $table->json('datos')->nullable();
            $table->string('archivo_firmado_path')->nullable();

            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generado_at');
            $table->timestamp('impreso_at')->nullable();
            $table->timestamp('firmado_at')->nullable();

            $table->timestamps();

            $table->index('venta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_venta');
    }
};
