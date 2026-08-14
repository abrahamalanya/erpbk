<?php

namespace Database\Seeders;

use App\Models\Agencia;
use App\Models\Empresa;
use Illuminate\Database\Seeder;

class AgenciaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $principal = Empresa::where('nombre', 'Empresa Principal')->firstOrFail();
        $secundaria = Empresa::where('nombre', 'Empresa Secundaria')->firstOrFail();

        foreach (['Agencia Lima', 'Agencia Arequipa', 'Agencia Trujillo'] as $nombre) {
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
