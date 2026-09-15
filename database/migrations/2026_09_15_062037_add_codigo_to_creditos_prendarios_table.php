<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Código único legible del crédito (compartido por los 4 tipos, ya que
     * todos viven en la misma tabla), estable y único sin necesidad de un
     * correlativo por empresa — mismo esquema que
     * add_codigo_to_garantias_tables (prefijo + id con ceros a la izquierda).
     */
    public function up(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->string('codigo')->nullable()->after('id');
        });

        DB::table('creditos_prendarios')->orderBy('id')->select(['id'])->each(function (object $fila): void {
            DB::table('creditos_prendarios')->where('id', $fila->id)->update([
                'codigo' => 'C-'.str_pad((string) $fila->id, 6, '0', STR_PAD_LEFT),
            ]);
        });

        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->unique(['empresa_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->dropUnique('creditos_prendarios_empresa_id_codigo_unique');
            $table->dropColumn('codigo');
        });
    }
};
