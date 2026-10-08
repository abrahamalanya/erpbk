<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identifica el comprobante (boleta/factura) que respalda el movimiento de
     * los módulos Ingresos y Egresos. Ambas columnas son opcionales — el
     * billetaje, las ventas y los desembolsos no llevan boleta — pero viajan
     * siempre juntas. El índice único evita que una misma boleta se registre
     * dos veces en la misma empresa (MySQL/SQLite ignoran los NULL en índices
     * únicos, así que los movimientos sin boleta no se bloquean entre sí).
     */
    public function up(): void
    {
        Schema::table('caja_movimientos', function (Blueprint $table): void {
            $table->string('serie', 10)->nullable()->after('descripcion');
            $table->string('numero', 20)->nullable()->after('serie');

            $table->unique(['empresa_id', 'serie', 'numero'], 'caja_movimientos_boleta_unica');
        });
    }

    public function down(): void
    {
        Schema::table('caja_movimientos', function (Blueprint $table): void {
            $table->dropUnique('caja_movimientos_boleta_unica');
            $table->dropColumn(['serie', 'numero']);
        });
    }
};
