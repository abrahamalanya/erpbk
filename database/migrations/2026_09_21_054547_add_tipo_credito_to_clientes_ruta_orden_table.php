<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La ruta de cobranza se recorre por tipo de crédito (diario, prendario,
     * hipotecario, vehicular), así que el orden de visita de un cliente pasa
     * a ser por (asesor, cliente, tipo). 'todos' es la ruta sin filtro y
     * conserva el orden que ya tenía cada asesor. Se usa un texto y no NULL
     * porque un índice único no distingue filas con NULL.
     *
     * El índice nuevo se crea ANTES de borrar el viejo: la FK de asesor_id
     * necesita siempre un índice que empiece por esa columna.
     */
    public function up(): void
    {
        Schema::table('clientes_ruta_orden', function (Blueprint $table): void {
            $table->string('tipo_credito', 20)->default('todos')->after('cliente_id');
        });

        Schema::table('clientes_ruta_orden', function (Blueprint $table): void {
            $table->unique(['asesor_id', 'cliente_id', 'tipo_credito']);
        });

        Schema::table('clientes_ruta_orden', function (Blueprint $table): void {
            $table->dropUnique(['asesor_id', 'cliente_id']);
        });
    }

    public function down(): void
    {
        // Sin la columna solo cabe un orden por cliente: se conserva el de la ruta general.
        DB::table('clientes_ruta_orden')->where('tipo_credito', '!=', 'todos')->delete();

        Schema::table('clientes_ruta_orden', function (Blueprint $table): void {
            $table->unique(['asesor_id', 'cliente_id']);
        });

        Schema::table('clientes_ruta_orden', function (Blueprint $table): void {
            $table->dropUnique(['asesor_id', 'cliente_id', 'tipo_credito']);
        });

        Schema::table('clientes_ruta_orden', function (Blueprint $table): void {
            $table->dropColumn('tipo_credito');
        });
    }
};
