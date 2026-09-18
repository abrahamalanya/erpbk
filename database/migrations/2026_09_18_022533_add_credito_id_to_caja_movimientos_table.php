<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('caja_movimientos', function (Blueprint $table) {
            // Solo lo setea CreditoService::desembolsar() — el único egreso
            // que corresponde a un crédito puntual (ver reportes/cajas).
            $table->foreignId('credito_id')->nullable()->after('billetaje_id')->constrained('creditos_prendarios')->nullOnDelete();
        });

        $this->backfillDesembolsosExistentes();
    }

    /**
     * Enlaza los egresos de desembolso ya existentes parseando el "#<id>"
     * final de su concepto (formato fijo de CreditoService::desembolsar():
     * "Desembolso de crédito prendario #{id}") — es la única forma de
     * recuperar ese enlace retroactivamente, ya que la columna no existía
     * antes de esta migración.
     */
    private function backfillDesembolsosExistentes(): void
    {
        DB::table('caja_movimientos')
            ->where('tipo', 'egreso')
            ->whereNull('concepto_id')
            ->whereNull('credito_id')
            ->where('concepto', 'like', '%#%')
            ->orderBy('id')
            ->get(['id', 'concepto'])
            ->each(function (object $row): void {
                if (! preg_match('/#(\d+)$/', (string) $row->concepto, $matches)) {
                    return;
                }

                $creditoId = (int) $matches[1];

                if (DB::table('creditos_prendarios')->where('id', $creditoId)->exists()) {
                    DB::table('caja_movimientos')->where('id', $row->id)->update(['credito_id' => $creditoId]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caja_movimientos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credito_id');
        });
    }
};
