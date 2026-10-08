<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega campo modo_facturacion a empresas para visualización rápida
     * en el listado de empresas (beta / produccion).
     * Los datos reales (credenciales, certificado) están en tablas relacionadas:
     * - credenciales_sunat (usuario_sol, clave_sol, certificado_path, etc.)
     * - configuraciones_facturacion (modo, igv_porcentaje, etc.)
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('modo_facturacion')->default('beta')->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('modo_facturacion');
        });
    }
};