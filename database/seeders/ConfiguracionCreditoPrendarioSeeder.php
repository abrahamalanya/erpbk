<?php

namespace Database\Seeders;

use App\Modules\Credito\Models\ConfiguracionCredito;
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

        ConfiguracionCredito::query()->firstOrCreate(
            ['empresa_id' => $credimas->id, 'agencia_id' => null, 'tipo_credito' => 'prendario'],
            [
                'interes_default' => 20,
                'plazo_dias' => 30,
                'dias_espera_mora' => 15,
                'dias_minimo_interes' => 15,
                'tasa_mora_diaria' => 1,
                'max_refrendos' => null,
            ]
        );

        ConfiguracionCredito::query()->firstOrCreate(
            ['empresa_id' => $credimas->id, 'agencia_id' => null, 'tipo_credito' => 'vehicular'],
            [
                'interes_default' => 15,
                'plazo_dias' => 30,
                'dias_espera_mora' => 15,
                'dias_minimo_interes' => 15,
                'tasa_mora_diaria' => 1,
                'max_refrendos' => null,
            ]
        );

        ConfiguracionCredito::query()->firstOrCreate(
            ['empresa_id' => $credimas->id, 'agencia_id' => null, 'tipo_credito' => 'hipotecario'],
            [
                'interes_default' => 10,
                'plazo_dias' => 30,
                'dias_espera_mora' => 30,
                'dias_minimo_interes' => 15,
                'tasa_mora_diaria' => 1,
                'max_refrendos' => null,
            ]
        );
    }
}
