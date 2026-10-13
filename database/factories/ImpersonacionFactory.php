<?php

namespace Database\Factories;

use App\Modules\Usuario\Models\Impersonacion;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Impersonacion>
 */
class ImpersonacionFactory extends Factory
{
    protected $model = Impersonacion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $impersonado = User::factory()->create();

        return [
            // Siempre la empresa del usuario IMPERSONADO, nunca la del actor
            // — ver ImpersonacionService::iniciar().
            'empresa_id' => $impersonado->empresa_id,
            'iniciado_por' => User::factory(),
            'impersonado_id' => $impersonado->id,
            'token_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'motivo' => null,
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'finalizado_at' => null,
        ];
    }

    /**
     * Mark the impersonación as already ended.
     */
    public function finalizada(): static
    {
        return $this->state(fn (): array => [
            'finalizado_at' => now(),
        ]);
    }
}
