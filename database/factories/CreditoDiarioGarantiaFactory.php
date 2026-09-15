<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoDiario\Models\CreditoDiarioGarantia;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditoDiarioGarantia>
 */
class CreditoDiarioGarantiaFactory extends Factory
{
    protected $model = CreditoDiarioGarantia::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'agencia_id' => Agencia::factory(),
            'cliente_id' => Cliente::factory(),
            'registrado_por' => User::factory(),
            'valorizacion' => fake()->randomFloat(2, 100, 3000),
            'estado' => 'en_garantia',
        ];
    }

    /**
     * Attach the garantía to the given cliente (and their empresa/agencia).
     */
    public function paraCliente(Cliente $cliente): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $cliente->empresa_id,
            'agencia_id' => $cliente->agencia_id,
            'cliente_id' => $cliente->id,
        ]);
    }
}
