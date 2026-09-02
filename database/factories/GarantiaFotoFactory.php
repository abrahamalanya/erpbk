<?php

namespace Database\Factories;

use App\Modules\Credito\Models\GarantiaFoto;
use App\Modules\CreditoPrendario\Models\Bien;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<GarantiaFoto>
 */
class GarantiaFotoFactory extends Factory
{
    protected $model = GarantiaFoto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bien = Bien::factory()->create();

        return [
            'garantia_type' => $bien->getMorphClass(),
            'garantia_id' => $bien->id,
            'path' => 'garantias/'.fake()->uuid().'.jpg',
            'orden' => 0,
        ];
    }

    /**
     * Attach this foto to the given garantía (Bien / Vehiculo / …).
     */
    public function paraGarantia(Model $garantia): static
    {
        return $this->state(fn (): array => [
            'garantia_type' => $garantia->getMorphClass(),
            'garantia_id' => $garantia->id,
        ]);
    }
}
