<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega campos para el resumen diario de boletas.
     */
    public function up(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            // Resumen diario
            $table->string('resumen_diario_ticket', 100)->nullable()->after('cdr_digests');
            $table->timestamp('resumen_diario_enviado_at')->nullable()->after('resumen_diario_ticket');
            $table->timestamp('resumen_diario_aceptado_at')->nullable()->after('resumen_diario_enviado_at');
            $table->string('resumen_diario_cdr_codigo', 10)->nullable()->after('resumen_diario_aceptado_at');

            // Índice para consultar boletas pendientes de resumen
            $table->index(['tipo_documento', 'fecha_emision', 'resumen_diario_enviado_at'], 'idx_comprobantes_resumen_diario');
        });
    }

    public function down(): void
    {
        Schema::table('comprobantes', function (Blueprint $table) {
            $table->dropIndex('idx_comprobantes_resumen_diario');
            $table->dropColumn([
                'resumen_diario_ticket',
                'resumen_diario_enviado_at',
                'resumen_diario_aceptado_at',
                'resumen_diario_cdr_codigo',
            ]);
        });
    }
};