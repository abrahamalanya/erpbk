<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las fotos de casa y negocio pasan de una columna string a varias: se crea
     * `cliente_fotos` (una fila por imagen, con `tipo` y `orden`, el mismo
     * shape que garantia_fotos / movimiento_fotos) y se leveragedan las fotos
     * que ya existieran como primera imagen de su tipo.
     *
     * De paso se agregan dos columnas de foto única: el suministro y el
     * recibo de luz, que el PDF del expediente ya tenía sus secciones
     * definidas (`suministro` y `recibo_servicio`) pero sin fuente de datos.
     */
    public function up(): void
    {
        Schema::create('cliente_fotos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')->constrained()->cascadeOnDelete();
            $table->string('tipo');
            $table->string('path');
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->index(['cliente_id', 'tipo', 'orden']);
        });

        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('foto_suministro_path')->nullable()->after('foto_dni_reverso_path');
            $table->string('foto_recibo_luz_path')->nullable()->after('foto_suministro_path');
        });

        // Query builder y no el modelo Cliente a propósito: esta migración debe
        // seguir funcionando aunque el modelo cambie más adelante, y las
        // columnas que copia se están borrando al final de este mismo up().
        $heredar = function (string $column, string $tipo): void {
            DB::table('clientes')
                ->select('id', $column)
                ->whereNotNull($column)
                ->orderBy('id')
                ->chunkById(200, function ($filas) use ($column, $tipo): void {
                    $ahora = now();

                    foreach ($filas as $fila) {
                        DB::table('cliente_fotos')->insert([
                            'cliente_id' => $fila->id,
                            'tipo' => $tipo,
                            'path' => $fila->{$column},
                            'orden' => 0,
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ]);
                    }
                }, 'id');
        };

        $heredar('foto_casa_path', 'casa');
        $heredar('foto_negocio_path', 'negocio');

        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn(['foto_casa_path', 'foto_negocio_path']);
        });
    }

    public function down(): void
    {
        // Las columnas tienen que volver a existir ANTES de intentar escribir en
        // ellas, y las de foto múltiple se reconstruyen con la imagen de menor
        // orden de cada tipo para no perder la principal. Las fotos
        // adicionales de casa/negocio no tienen columna donde volver a caer.
        Schema::table('clientes', function (Blueprint $table): void {
            $table->string('foto_casa_path')->nullable()->after('foto_dni_reverso_path');
            $table->string('foto_negocio_path')->nullable()->after('foto_casa_path');
        });

        DB::table('clientes')->orderBy('id')->each(function (object $cliente): void {
            $porTipo = DB::table('cliente_fotos')
                ->where('cliente_id', $cliente->id)
                ->orderBy('orden')
                ->get()
                ->groupBy('tipo');

            DB::table('clientes')->where('id', $cliente->id)->update([
                'foto_casa_path' => $porTipo->get('casa')?->first()?->path,
                'foto_negocio_path' => $porTipo->get('negocio')?->first()?->path,
            ]);
        });

        Schema::table('clientes', function (Blueprint $table): void {
            $table->dropColumn(['foto_suministro_path', 'foto_recibo_luz_path']);
        });

        Schema::dropIfExists('cliente_fotos');
    }
};
