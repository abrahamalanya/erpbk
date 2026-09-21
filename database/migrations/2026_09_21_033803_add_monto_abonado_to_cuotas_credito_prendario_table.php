<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `monto_abonado` es lo ya pagado de una cuota que todavía no se cubrió
     * completa (pago parcial / adelanto de un diario). Cuando llega a
     * `monto_total` la cuota se marca `pagada_at`.
     */
    public function up(): void
    {
        Schema::table('cuotas_credito_prendario', function (Blueprint $table): void {
            $table->decimal('monto_abonado', 10, 2)->default(0)->after('monto_total');
        });
    }

    public function down(): void
    {
        Schema::table('cuotas_credito_prendario', function (Blueprint $table): void {
            $table->dropColumn('monto_abonado');
        });
    }
};
