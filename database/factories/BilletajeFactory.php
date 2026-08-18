<?php

namespace Database\Factories;

use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CajaCiclo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Billetaje>
 */
class BilletajeFactory extends Factory
{
    protected $model = Billetaje::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cajaCiclo = CajaCiclo::factory()->create();

        return [
            'caja_ciclo_id' => $cajaCiclo->id,
            'boveda_id' => Boveda::factory(),
            'empresa_id' => $cajaCiclo->empresa_id,
            'monto' => fake()->randomFloat(2, 50, 1000),
            'estado' => 'pendiente',
            'solicitado_por' => $cajaCiclo->caja->user_id,
        ];
    }

    /**
     * Attach this solicitud to the given ciclo/bóveda.
     */
    public function paraCicloYBoveda(CajaCiclo $cajaCiclo, Boveda $boveda): static
    {
        return $this->state(fn (): array => [
            'caja_ciclo_id' => $cajaCiclo->id,
            'boveda_id' => $boveda->id,
            'empresa_id' => $cajaCiclo->empresa_id,
            'solicitado_por' => $cajaCiclo->caja->user_id,
        ]);
    }
}
