<?php

namespace Database\Factories;

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\BienFoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BienFoto>
 */
class BienFotoFactory extends Factory
{
    protected $model = BienFoto::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bien_id' => Bien::factory(),
            'path' => 'bienes/'.fake()->uuid().'.jpg',
            'orden' => 0,
        ];
    }

    /**
     * Attach this foto to the given bien.
     */
    public function paraBien(Bien $bien): static
    {
        return $this->state(fn (): array => [
            'bien_id' => $bien->id,
        ]);
    }
}
