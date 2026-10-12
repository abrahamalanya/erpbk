<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tasas legales con las que se arma la liquidación de saldo deudor de un
     * crédito hipotecario: la TEA compensatoria máxima (tope BCRP) y la tasa
     * moratoria anual (Art. 1246 C.C.). Son porcentajes anuales efectivos que
     * cambian cada semestre, por eso viven en la configuración y no en código.
     * Nulas hasta que el administrador las registre.
     */
    public function up(): void
    {
        Schema::table('configuraciones_credito_prendario', function (Blueprint $table): void {
            $table->decimal('tea_compensatoria_maxima', 7, 2)->nullable()->after('max_cuotas');
            $table->decimal('tasa_moratoria_anual', 7, 2)->nullable()->after('tea_compensatoria_maxima');
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_credito_prendario', function (Blueprint $table): void {
            $table->dropColumn(['tea_compensatoria_maxima', 'tasa_moratoria_anual']);
        });
    }
};
