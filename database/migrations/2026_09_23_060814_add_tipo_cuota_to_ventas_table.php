<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frecuencia del cronograma de una venta a crédito (forma_venta =
     * credito): diario | semanal | quincenal | mensual. Solo aplica a esa
     * modalidad, igual que interes/numero_cuotas — ver
     * VentaService::calcularCronograma().
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('tipo_cuota')->nullable()->after('numero_cuotas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('tipo_cuota');
        });
    }
};
