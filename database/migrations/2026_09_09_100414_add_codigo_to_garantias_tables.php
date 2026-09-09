<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Código único legible por garantía (impreso en el sticker del producto).
     * Se deriva del id con un prefijo por tipo, así que es estable y único
     * sin necesidad de un correlativo por empresa.
     *
     * @var array<string, string>
     */
    private const PREFIJOS = [
        'bienes' => 'B',
        'vehiculos' => 'V',
        'inmuebles' => 'I',
    ];

    public function up(): void
    {
        foreach (self::PREFIJOS as $tabla => $prefijo) {
            Schema::table($tabla, function (Blueprint $table): void {
                $table->string('codigo')->nullable()->after('id');
            });

            DB::table($tabla)->orderBy('id')->select(['id'])->each(function (object $fila) use ($tabla, $prefijo): void {
                DB::table($tabla)->where('id', $fila->id)->update([
                    'codigo' => $prefijo.'-'.str_pad((string) $fila->id, 6, '0', STR_PAD_LEFT),
                ]);
            });

            Schema::table($tabla, function (Blueprint $table): void {
                $table->unique(['empresa_id', 'codigo']);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PREFIJOS) as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla): void {
                $table->dropUnique($tabla.'_empresa_id_codigo_unique');
                $table->dropColumn('codigo');
            });
        }
    }
};
