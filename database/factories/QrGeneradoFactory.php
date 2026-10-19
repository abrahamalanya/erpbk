<?php

namespace Database\Factories;

use App\Modules\Qr\Models\QrGenerado;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QrGenerado>
 */
class QrGeneradoFactory extends Factory
{
    protected $model = QrGenerado::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'url' => fake()->url(),
            'user_id' => User::factory(),
            'generado_por' => fake()->name(),
        ];
    }
}
