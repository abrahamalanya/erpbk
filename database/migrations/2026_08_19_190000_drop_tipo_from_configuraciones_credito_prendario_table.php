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
        // Electro/Varios turned out to always carry the same values in
        // practice — before dropping `tipo`, keep only the most recently
        // updated row per (empresa_id, agencia_id) so no unique constraint
        // violation happens when the old tipo-scoped index goes away.
        $grupos = DB::table('configuraciones_credito_prendario')
            ->select('empresa_id', 'agencia_id')
            ->groupBy('empresa_id', 'agencia_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($grupos as $grupo) {
            $ids = DB::table('configuraciones_credito_prendario')
                ->where('empresa_id', $grupo->empresa_id)
                ->where(fn ($query) => $grupo->agencia_id === null
                    ? $query->whereNull('agencia_id')
                    : $query->where('agencia_id', $grupo->agencia_id))
                ->orderByDesc('updated_at')
                ->pluck('id');

            DB::table('configuraciones_credito_prendario')
                ->whereIn('id', $ids->slice(1))
                ->delete();
        }

        // MySQL/InnoDB won't drop the old composite unique index in the same
        // step as adding the new one — it's the only index currently backing
        // the empresa_id/agencia_id foreign keys, so the replacement has to
        // exist first.
        Schema::table('configuraciones_credito_prendario', function (Blueprint $table) {
            $table->unique(['empresa_id', 'agencia_id'], 'config_credito_prendario_empresa_agencia_unique');
        });

        Schema::table('configuraciones_credito_prendario', function (Blueprint $table) {
            $table->dropUnique('config_credito_prendario_empresa_agencia_tipo_unique');
            $table->dropColumn('tipo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('configuraciones_credito_prendario', function (Blueprint $table) {
            $table->string('tipo')->nullable()->after('agencia_id');
        });

        Schema::table('configuraciones_credito_prendario', function (Blueprint $table) {
            $table->unique(['empresa_id', 'agencia_id', 'tipo'], 'config_credito_prendario_empresa_agencia_tipo_unique');
        });

        Schema::table('configuraciones_credito_prendario', function (Blueprint $table) {
            $table->dropUnique('config_credito_prendario_empresa_agencia_unique');
        });
    }
};
