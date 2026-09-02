<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inmueble>
 */
class InmuebleFactory extends Factory
{
    protected $model = Inmueble::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'agencia_id' => Agencia::factory(),
            'cliente_id' => Cliente::factory(),
            'registrado_por' => User::factory(),
            'partida_registral' => 'P'.fake()->numerify('########'),
            'oficina_registral' => fake()->randomElement(['Lima', 'Pucallpa', 'Arequipa']),
            'tipo_inmueble' => fake()->randomElement(['Casa', 'Departamento', 'Terreno', 'Local comercial']),
            'direccion' => fake()->streetAddress(),
            'distrito' => fake()->city(),
            'provincia' => fake()->city(),
            'departamento' => fake()->randomElement(['Ucayali', 'Lima', 'Arequipa']),
            'area_terreno' => fake()->randomFloat(2, 60, 500),
            'area_construida' => fake()->randomFloat(2, 40, 400),
            'propietario' => fake()->name(),
            'con_gravamen' => false,
            'linderos' => fake()->sentence(),
            'observacion' => fake()->sentence(),
            'valorizacion' => fake()->randomFloat(2, 40000, 400000),
            'puntaje' => fake()->numberBetween(1, 10),
            'estado' => 'en_garantia',
        ];
    }

    public function forAgencia(Agencia $agencia): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $agencia->empresa_id,
            'agencia_id' => $agencia->id,
            'cliente_id' => Cliente::factory()->forAgencia($agencia),
        ]);
    }

    public function paraCliente(Cliente $cliente): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $cliente->empresa_id,
            'agencia_id' => $cliente->agencia_id,
            'cliente_id' => $cliente->id,
            'propietario' => trim("{$cliente->nombre} {$cliente->apellido}"),
        ]);
    }
}
