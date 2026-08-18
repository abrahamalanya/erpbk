<?php

namespace Database\Factories;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'estado' => 'activo',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user belongs to the given empresa (no agencia).
     */
    public function forEmpresa(Empresa $empresa): static
    {
        return $this->state(fn (array $attributes) => [
            'empresa_id' => $empresa->id,
        ]);
    }

    /**
     * Indicate that the user belongs to the given agencia (and its empresa).
     */
    public function forAgencia(Agencia $agencia): static
    {
        return $this->state(fn (array $attributes) => [
            'empresa_id' => $agencia->empresa_id,
            'agencia_id' => $agencia->id,
        ]);
    }
}
