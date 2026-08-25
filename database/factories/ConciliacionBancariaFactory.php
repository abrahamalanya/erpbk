<?php

namespace Database\Factories;

use App\Modules\Caja\Models\ConciliacionBancaria;
use App\Modules\Caja\Models\CuentaBancaria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConciliacionBancaria>
 */
class ConciliacionBancariaFactory extends Factory
{
    protected $model = ConciliacionBancaria::class;

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
            'saldo_sistema' => 0,
            'saldo_banco' => 0,
            'diferencia' => 0,
            'observacion' => null,
            'conciliado_por' => null,
            'fecha' => now()->toDateString(),
        ];
    }

    /**
     * Attach this conciliación to the given cuenta bancaria.
     */
    public function paraCuenta(CuentaBancaria $cuentaBancaria): static
    {
        return $this->state(fn (): array => [
            'cuenta_bancaria_id' => $cuentaBancaria->id,
            'empresa_id' => $cuentaBancaria->empresa_id,
        ]);
    }
}
