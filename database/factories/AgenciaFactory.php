<?php

namespace Database\Factories;

use App\Models\Agencia;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agencia>
 */
class AgenciaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'nombre' => 'Agencia '.fake()->unique()->city(),
            'estado' => 'activo',
        ];
    }
}
