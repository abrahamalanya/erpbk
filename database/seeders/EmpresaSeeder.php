<?php

namespace Database\Seeders;

use App\Modules\Empresa\Models\Empresa;
use Illuminate\Database\Seeder;

class EmpresaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Empresa::create(['nombre' => 'Empresa Principal', 'estado' => 'activo']);
        Empresa::create(['nombre' => 'Empresa Secundaria', 'estado' => 'activo']);
    }
}
