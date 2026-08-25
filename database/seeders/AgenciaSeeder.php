<?php

namespace Database\Seeders;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use Illuminate\Database\Seeder;

class AgenciaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $principal = Empresa::where('nombre', 'CREDIMAS')->firstOrFail();
        $secundaria = Empresa::where('nombre', 'Empresa Secundaria')->firstOrFail();

        foreach (['Agencia Pucallpa', 'Agencia Juanjui', 'Agencia Tocache'] as $nombre) {
            Agencia::create([
                'empresa_id' => $principal->id,
                'nombre' => $nombre,
                'estado' => 'activo',
            ]);
        }

        Agencia::create([
            'empresa_id' => $secundaria->id,
            'nombre' => 'Agencia Cusco',
            'estado' => 'activo',
        ]);
    }
}
