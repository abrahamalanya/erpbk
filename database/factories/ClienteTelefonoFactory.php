<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Models\ClienteTelefono;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClienteTelefono>
 */
class ClienteTelefonoFactory extends Factory
{
    protected $model = ClienteTelefono::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'numero' => fake()->phoneNumber(),
            'orden' => 0,
        ];
    }

    /**
     * Attach this telefono to the given cliente.
     */
    public function paraCliente(Cliente $cliente): static
    {
        return $this->state(fn (): array => ['cliente_id' => $cliente->id]);
    }
}
