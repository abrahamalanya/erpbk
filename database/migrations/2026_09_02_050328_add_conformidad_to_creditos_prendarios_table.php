<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conformidad notario/abogado: for tipos that require it (vehicular,
     * hipotecario), a vencido crédito moves to "pendiente_conformidad" after
     * the días de espera and only reaches "en_venta" once this scanned PDF
     * is uploaded. Prendario never uses these columns.
     */
    public function up(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table) {
            $table->string('conformidad_path')->nullable()->after('fecha_vencimiento');
            $table->timestamp('conformidad_confirmada_at')->nullable()->after('conformidad_path');
        });
    }

    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table) {
            $table->dropColumn(['conformidad_path', 'conformidad_confirmada_at']);
        });
    }
};
