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
        Empresa::create([
            'nombre' => 'CREDIMAS',
            'ruc' => '20602137903',
            'razon_social' => 'CREDIMAS ORIENTE E.I.R.L.',
            'domicilio_legal' => 'CAL.SAN PEDRO/SAN TOMAS MZA. A LOTE. 12 (A.H. LOS OLVOS) YARINACOCHA - CORONEL PORTILLO - UCAYALI',
            'actividad_economica' => 'CONCESIÓN DE CRÉDITO',
            'representante_legal' => 'OSORES PAUCARCHUCO PABLO ELVIS',
            'estado' => 'activo',
        ]);
        Empresa::create(['nombre' => 'Empresa Secundaria', 'estado' => 'activo']);
    }
}
