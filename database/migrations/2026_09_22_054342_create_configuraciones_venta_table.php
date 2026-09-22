<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasa de interés por defecto para la venta a crédito, por
     * empresa/agencia — mirror exacto de configuraciones_credito_prendario
     * (agencia_id nulo = fila default de la empresa). Ver
     * ConfiguracionVentaService::resolverPara().
     */
    public function up(): void
    {
        Schema::create('configuraciones_venta', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();

            $table->decimal('interes_mensual_default', 5, 2);

            $table->timestamps();

            $table->unique(['empresa_id', 'agencia_id'], 'configuraciones_venta_empresa_agencia_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_venta');
    }
};
