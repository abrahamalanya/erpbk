<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cliente>
 */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

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
            'asesor_id' => null,
            'registrado_por' => User::factory(),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'tipo_documento' => 'dni',
            'numero_documento' => fake()->unique()->numerify('########'),
            'telefono' => fake()->phoneNumber(),
            'direccion' => fake()->address(),
            'referencia' => fake()->sentence(),
            'estado' => 'activo',
        ];
    }

    /**
     * Attach the cliente to the given agencia (and its empresa).
     */
    public function forAgencia(Agencia $agencia): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $agencia->empresa_id,
            'agencia_id' => $agencia->id,
        ]);
    }

    /**
     * Assign the cliente to the given asesor.
     */
    public function asignadoA(User $asesor): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $asesor->empresa_id,
            'agencia_id' => $asesor->agencia_id,
            'asesor_id' => $asesor->id,
        ]);
    }

    /**
     * Mark the cliente as registered by the given user.
     */
    public function registradoPor(User $peinadora): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $peinadora->empresa_id,
            'agencia_id' => $peinadora->agencia_id,
            'registrado_por' => $peinadora->id,
        ]);
    }
}
