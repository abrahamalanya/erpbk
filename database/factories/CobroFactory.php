<?php

namespace Database\Factories;

use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\Credito;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cobro>
 */
class CobroFactory extends Factory
{
    protected $model = Cobro::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'cliente_id' => Cliente::factory(),
            'credito_id' => Credito::factory(),
            'credito_sucesor_id' => null,
            'caja_ciclo_id' => null,
            'registrado_por' => User::factory(),
            'operacion' => fake()->randomElement(['refrendo', 'adenda', 'liquidacion']),
            'monto_pagado' => fake()->randomFloat(2, 50, 1500),
            'medio' => fake()->randomElement(['efectivo', 'yape', 'plin', 'transferencia']),
            'interes' => fake()->randomFloat(2, 10, 300),
            'mora' => null,
            'vuelto' => 0,
        ];
    }
}
