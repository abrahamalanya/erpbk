<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
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
            'cliente_id' => Cliente::factory(),
            'registrado_por' => User::factory(),
            'tipo' => 'electro',
            'nombre' => fake()->words(2, true),
            'marca' => fake()->company(),
            'modelo' => fake()->bothify('MOD-####'),
            'serie' => fake()->bothify('SN-########'),
            'observacion' => fake()->sentence(),
            'valorizacion' => fake()->randomFloat(2, 100, 3000),
            'puntaje' => fake()->numberBetween(1, 10),
            'estado' => 'en_garantia',
        ];
    }

    /**
     * Attach the bien to the given agencia (and its empresa), with a fresh
     * cliente of that same agencia.
     */
    public function forAgencia(Agencia $agencia): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $agencia->empresa_id,
            'agencia_id' => $agencia->id,
            'cliente_id' => Cliente::factory()->forAgencia($agencia),
        ]);
    }

    /**
     * Attach the bien to the given cliente (and their empresa/agencia) —
     * for tests that need several bienes under the same cliente.
     */
    public function paraCliente(Cliente $cliente): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $cliente->empresa_id,
            'agencia_id' => $cliente->agencia_id,
            'cliente_id' => $cliente->id,
        ]);
    }

    /**
     * Mark the bien as tipo "varios" (serie may be absent; marca/modelo are
     * still required for every tipo).
     */
    public function varios(): static
    {
        return $this->state(fn (): array => [
            'tipo' => 'varios',
            'serie' => null,
        ]);
    }
}
