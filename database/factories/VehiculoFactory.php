<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehiculo>
 */
class VehiculoFactory extends Factory
{
    protected $model = Vehiculo::class;

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
            'placa' => strtoupper(fake()->bothify('???-###')),
            'motor' => fake()->bothify('MTR-########'),
            'serie' => fake()->bothify('VIN-#################'),
            'color' => fake()->safeColorName(),
            'marca' => fake()->randomElement(['Toyota', 'Hyundai', 'Kia', 'Nissan', 'Suzuki']),
            'modelo' => fake()->bothify('Serie ##'),
            'anio' => fake()->numberBetween(2005, 2025),
            'clase' => fake()->randomElement(['Automóvil', 'Camioneta', 'Motocicleta']),
            'propietario' => fake()->name(),
            'tiene_soat' => fake()->boolean(),
            'dejo_llave' => fake()->boolean(),
            'dejo_tarjeta_propiedad' => fake()->boolean(),
            'observacion' => fake()->sentence(),
            'valorizacion' => fake()->randomFloat(2, 3000, 40000),
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
