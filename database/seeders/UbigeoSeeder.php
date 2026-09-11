<?php

namespace Database\Seeders;

use App\Modules\Ubigeo\Models\UbigeoDepartamento;
use App\Modules\Ubigeo\Models\UbigeoProvincia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Carga el catálogo de ubigeo (INEI) desde database/data/ubigeo/*.json —
 * departamento/provincia/distrito de todo el Perú, usado por Cliente e
 * Inmueble. Idempotente (upsert por código) y liviano: inserta en bloque en
 * vez de crear modelo por modelo (miles de distritos).
 */
class UbigeoSeeder extends Seeder
{
    public function run(): void
    {
        $dir = database_path('data/ubigeo');

        $departamentos = json_decode(file_get_contents("{$dir}/departamentos.json"), true);
        $provincias = json_decode(file_get_contents("{$dir}/provincias.json"), true);
        $distritos = json_decode(file_get_contents("{$dir}/distritos.json"), true);

        DB::table('ubigeo_departamentos')->upsert(
            array_map(fn (array $d): array => [
                'codigo' => $d['codigo'], 'nombre' => $d['nombre'],
                'created_at' => now(), 'updated_at' => now(),
            ], $departamentos),
            ['codigo'],
            ['nombre', 'updated_at'],
        );

        $departamentoIdPorCodigo = UbigeoDepartamento::query()->pluck('id', 'codigo');

        DB::table('ubigeo_provincias')->upsert(
            array_map(fn (array $p): array => [
                'ubigeo_departamento_id' => $departamentoIdPorCodigo[$p['codigo_departamento']],
                'codigo' => $p['codigo'], 'nombre' => $p['nombre'],
                'created_at' => now(), 'updated_at' => now(),
            ], $provincias),
            ['ubigeo_departamento_id', 'codigo'],
            ['nombre', 'updated_at'],
        );

        // Clave compuesta "codigo_departamento-codigo_provincia" -> id, para
        // resolver la provincia de cada distrito sin un query por fila.
        $provinciaIdPorClave = UbigeoProvincia::query()
            ->join('ubigeo_departamentos', 'ubigeo_departamentos.id', '=', 'ubigeo_provincias.ubigeo_departamento_id')
            ->select('ubigeo_provincias.id as id', DB::raw("CONCAT(ubigeo_departamentos.codigo, '-', ubigeo_provincias.codigo) as clave"))
            ->get()
            ->pluck('id', 'clave');

        DB::table('ubigeo_distritos')->upsert(
            array_map(fn (array $d): array => [
                'ubigeo_provincia_id' => $provinciaIdPorClave["{$d['codigo_departamento']}-{$d['codigo_provincia']}"],
                'codigo' => $d['codigo'], 'nombre' => $d['nombre'],
                'created_at' => now(), 'updated_at' => now(),
            ], $distritos),
            ['ubigeo_provincia_id', 'codigo'],
            ['nombre', 'updated_at'],
        );
    }
}
