<?php

namespace Database\Factories;

use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Caja\Models\CuentaBancariaMovimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuentaBancariaMovimiento>
 */
class CuentaBancariaMovimientoFactory extends Factory
{
    protected $model = CuentaBancariaMovimiento::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cuentaBancaria = CuentaBancaria::factory()->create();

        return [
            'cuenta_bancaria_id' => $cuentaBancaria->id,
            'empresa_id' => $cuentaBancaria->empresa_id,
            'tipo' => 'ingreso',
            'monto' => fake()->randomFloat(2, 10, 500),
            'concepto' => fake()->sentence(3),
            'registrado_por' => null,
            'fecha' => now()->toDateString(),
        ];
    }

    /**
     * Attach this movimiento to the given cuenta bancaria.
     */
    public function paraCuenta(CuentaBancaria $cuentaBancaria): static
    {
        return $this->state(fn (): array => [
            'cuenta_bancaria_id' => $cuentaBancaria->id,
            'empresa_id' => $cuentaBancaria->empresa_id,
        ]);
    }
}
