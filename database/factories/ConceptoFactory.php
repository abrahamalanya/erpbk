<?php

namespace Database\Factories;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\Concepto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Concepto>
 */
class ConceptoFactory extends Factory
{
    protected $model = Concepto::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'tipo' => fake()->randomElement(['ingreso', 'gasto']),
            'nombre' => fake()->unique()->words(2, true),
            'activo' => true,
            'creado_por' => null,
        ];
    }

    /**
     * Attach this concepto to the given empresa.
     */
    public function paraEmpresa(Empresa $empresa): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $empresa->id,
        ]);
    }
}
