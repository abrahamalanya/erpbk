<?php

namespace Database\Factories;

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bien>
 */
class BienFactory extends Factory
{
    protected $model = Bien::class;

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
            'registrado_por' => User::factory(),
            'tipo' => 'electro',
            'nombre' => fake()->words(2, true),
            'marca' => fake()->company(),
            'modelo' => fake()->bothify('MOD-####'),
            'serie' => fake()->bothify('SN-########'),
            'observacion' => fake()->sentence(),
            'valorizacion' => fake()->randomFloat(2, 100, 3000),
            'cantidad' => 1,
            'estado' => 'en_garantia',
        ];
    }

    /**
     * Attach the bien to the given agencia (and its empresa).
     */
    public function forAgencia(Agencia $agencia): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $agencia->empresa_id,
            'agencia_id' => $agencia->id,
        ]);
    }

    /**
     * Mark the bien as tipo "varios" (marca/modelo/serie may be absent).
     */
    public function varios(): static
    {
        return $this->state(fn (): array => [
            'tipo' => 'varios',
            'marca' => null,
            'modelo' => null,
            'serie' => null,
        ]);
    }
}
