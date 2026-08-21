<?php

namespace Database\Factories;

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Tienda\Models\InteresBien;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InteresBien>
 */
class InteresBienFactory extends Factory
{
    protected $model = InteresBien::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bien_id' => Bien::factory(),
            'empresa_id' => Empresa::factory(),
            'agencia_id' => Agencia::factory(),
            'nombre' => fake()->name(),
            'telefono' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'mensaje' => fake()->sentence(),
        ];
    }

    /**
     * Attach the interés to the given bien (and its empresa/agencia).
     */
    public function paraBien(Bien $bien): static
    {
        return $this->state(fn (): array => [
            'bien_id' => $bien->id,
            'empresa_id' => $bien->empresa_id,
            'agencia_id' => $bien->agencia_id,
        ]);
    }
}
