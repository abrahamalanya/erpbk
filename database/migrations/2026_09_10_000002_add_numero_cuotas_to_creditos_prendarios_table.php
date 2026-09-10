<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Número de cuotas elegido por el asesor al registrar el crédito. Nullable
     * a propósito: los créditos previos y los sucesores de refrendo / adenda
     * lo dejan en null y caen al default por tipo_cuota (CreditoService::
     * CUOTAS_POR_TIPO). Al desembolsar se usa este valor si está presente.
     */
    public function up(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->unsignedInteger('numero_cuotas')->nullable()->after('tipo_cuota');
        });
    }

    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->dropColumn('numero_cuotas');
        });
    }
};
