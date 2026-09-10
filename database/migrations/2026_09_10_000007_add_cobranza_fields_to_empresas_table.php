<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos que firman / encabezan los documentos de cobranza de créditos
     * hipotecarios (notificación / requerimiento de pago y carta de aviso
     * prejudicial): el apoderado legal y el celular de cobranzas.
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->string('apoderado_legal')->nullable()->after('representante_legal');
            $table->string('celular_cobranzas')->nullable()->after('apoderado_legal');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->dropColumn(['apoderado_legal', 'celular_cobranzas']);
        });
    }
};
