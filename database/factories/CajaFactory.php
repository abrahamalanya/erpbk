<?php

namespace Database\Factories;

use App\Modules\Caja\Models\Caja;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Caja>
 */
class CajaFactory extends Factory
{
    protected $model = Caja::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory()->create();

        return [
            'user_id' => $user->id,
            'empresa_id' => $user->empresa_id,
            'agencia_id' => $user->agencia_id,
        ];
    }

    /**
     * Attach this caja to the given user.
     */
    public function paraUsuario(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'empresa_id' => $user->empresa_id,
            'agencia_id' => $user->agencia_id,
        ]);
    }
}
