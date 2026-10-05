<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Models\ClienteFoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClienteFoto>
 */
class ClienteFotoFactory extends Factory
{
    protected $model = ClienteFoto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cliente_id' => Cliente::factory(),
            'tipo' => ClienteFoto::TIPO_CASA,
            'path' => 'clientes/'.fake()->uuid().'.jpg',
            'orden' => 0,
        ];
    }

    public function deTipo(string $tipo): static
    {
        return $this->state(fn (): array => ['tipo' => $tipo]);
    }

    /**
     * Attach this foto to the given cliente.
     */
    public function paraCliente(Cliente $cliente): static
    {
        return $this->state(fn (): array => ['cliente_id' => $cliente->id]);
    }
}
