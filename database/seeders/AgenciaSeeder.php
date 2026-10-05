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

        // telefono: lo imprime el aviso de extravío de la fotocheck. Sin esto
        // la tarjeta cae al celular de cobranzas de la empresa.
        foreach (['Agencia Alameda' => '511234567'] as $nombre => $telefono) {
            Agencia::firstOrCreate([
                'empresa_id' => $principal->id,
                'nombre' => $nombre,
            ], ['telefono' => $telefono, 'estado' => 'activo']);
        }

        // Agencia de la empresa de demostración: solo local (ver EmpresaSeeder).
        if (app()->environment('local')) {
            $secundaria = Empresa::where('nombre', 'Empresa Secundaria')->firstOrFail();

            Agencia::firstOrCreate([
                'empresa_id' => $secundaria->id,
                'nombre' => 'Agencia Cusco',
            ], ['telefono' => '084221090', 'estado' => 'activo']);
        }
    }
}
