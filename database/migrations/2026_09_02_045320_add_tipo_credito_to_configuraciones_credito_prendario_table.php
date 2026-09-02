<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A configuración row is now scoped per tipo de crédito, so each empresa/
     * agencia can set distinct interés, plazos, mora and días de espera for
     * prendario vs vehicular vs hipotecario.
     */
    public function up(): void
    {
        Schema::table('configuraciones_credito_prendario', function (Blueprint $table) {
            $table->string('tipo_credito')->default('prendario')->after('agencia_id');
        });
    }

    public function down(): void
    {
        Schema::table('configuraciones_credito_prendario', function (Blueprint $table) {
            $table->dropColumn('tipo_credito');
        });
    }
};
