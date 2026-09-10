<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\FichaSocioeconomica;
use App\Modules\Cliente\Models\FichaSocioeconomicaFamiliar;
use App\Modules\Empresa\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FichaSocioeconomicaFamiliar>
 */
class FichaSocioeconomicaFamiliarFactory extends Factory
{
    protected $model = FichaSocioeconomicaFamiliar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'ficha_socioeconomica_id' => FichaSocioeconomica::factory(),
            'nombres' => fake()->name(),
            'edad' => fake()->numberBetween(1, 90),
            'parentesco' => fake()->randomElement(['hijo', 'esposa', 'madre', 'hermano']),
            'estado_civil' => fake()->randomElement(['soltero', 'casado']),
            'ocupacion' => fake()->jobTitle(),
        ];
    }
}
