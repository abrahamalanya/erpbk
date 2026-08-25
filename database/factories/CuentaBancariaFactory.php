<?php

namespace Database\Factories;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Nucleo\Models\Banco;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuentaBancaria>
 */
class CuentaBancariaFactory extends Factory
{
    protected $model = CuentaBancaria::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'boveda_id' => Boveda::factory(),
            'empresa_id' => fn (array $attributes) => Boveda::find($attributes['boveda_id'])->empresa_id,
            'banco_id' => Banco::factory(),
            'numero_cuenta' => fake()->numerify('###-#########-#-##'),
            'titular' => fake()->company(),
            'tipo_cuenta' => fake()->randomElement(['ahorro', 'corriente']),
            'moneda' => 'PEN',
            'alias' => null,
            'activa' => true,
            'saldo_inicial' => 0,
            'creada_por' => null,
        ];
    }

    /**
     * Attach this cuenta bancaria to the given bóveda.
     */
    public function paraBoveda(Boveda $boveda): static
    {
        return $this->state(fn (): array => [
            'boveda_id' => $boveda->id,
            'empresa_id' => $boveda->empresa_id,
        ]);
    }
}
