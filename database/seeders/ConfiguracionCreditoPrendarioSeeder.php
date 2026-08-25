<?php

namespace Database\Seeders;

use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\Empresa\Models\Empresa;
use Illuminate\Database\Seeder;

class ConfiguracionCreditoPrendarioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $credimas = Empresa::where('nombre', 'CREDIMAS')->firstOrFail();

        ConfiguracionCreditoPrendario::query()->firstOrCreate(
            ['empresa_id' => $credimas->id, 'agencia_id' => null],
            [
                'interes_default' => 20,
                'plazo_dias' => 30,
                'dias_espera_mora' => 15,
                'dias_minimo_interes' => 15,
                'tasa_mora_diaria' => 1,
                'max_refrendos' => null,
            ]
        );
    }
}
