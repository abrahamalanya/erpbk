<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Simulador\Models\SimulacionCredito;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SimulacionCredito>
 */
class SimulacionCreditoFactory extends Factory
{
    protected $model = SimulacionCredito::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $monto = fake()->randomFloat(2, 100, 2000);
        $interes = fake()->randomFloat(2, 5, 20);

        return [
            'empresa_id' => Empresa::factory(),
            'agencia_id' => Agencia::factory(),
            'tipo_credito' => 'prendario',
            'cliente_id' => Cliente::factory(),
            'registrado_por' => User::factory(),
            'monto_prestamo' => $monto,
            'interes' => $interes,
            'tipo_cuota' => 'mensual',
            'numero_cuotas' => 1,
            'plazo_dias' => 30,
            'fecha_base' => now()->toDateString(),
            'monto_total_pagar' => bcadd((string) $monto, bcmul((string) $monto, bcdiv((string) $interes, '100', 4), 2), 2),
            'cronograma' => [],
        ];
    }
}
