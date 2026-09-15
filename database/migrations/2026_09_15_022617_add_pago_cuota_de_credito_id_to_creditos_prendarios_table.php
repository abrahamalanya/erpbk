<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table) {
            // Mismo patrón que refrendo_de_credito_id/adenda_de_credito_id: el
            // sucesor que nace al pagar una cuota de un crédito de interés
            // compuesto (ver CreditoService::pagarCuota()).
            $table->foreignId('pago_cuota_de_credito_id')->nullable()
                ->constrained('creditos_prendarios')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pago_cuota_de_credito_id');
        });
    }
};
