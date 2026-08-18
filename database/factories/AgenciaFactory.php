<?php

namespace Database\Factories;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agencia>
 */
class AgenciaFactory extends Factory
{
    protected $model = Agencia::class;

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
