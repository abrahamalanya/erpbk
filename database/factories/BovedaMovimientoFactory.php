<?php

namespace Database\Factories;

use App\Modules\Caja\Models\BovedaCiclo;
use App\Modules\Caja\Models\BovedaMovimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BovedaMovimiento>
 */
class BovedaMovimientoFactory extends Factory
{
    protected $model = BovedaMovimiento::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bovedaCiclo = BovedaCiclo::factory()->create();

        return [
            'boveda_ciclo_id' => $bovedaCiclo->id,
            'empresa_id' => $bovedaCiclo->empresa_id,
            'tipo' => 'ingreso',
            'monto' => fake()->randomFloat(2, 10, 500),
            'concepto' => fake()->sentence(3),
            'registrado_por' => $bovedaCiclo->abierta_por,
            'fecha_boveda' => $bovedaCiclo->fecha,
        ];
    }

    /**
     * Attach this movimiento to the given ciclo.
     */
    public function paraCiclo(BovedaCiclo $bovedaCiclo): static
    {
        return $this->state(fn (): array => [
            'boveda_ciclo_id' => $bovedaCiclo->id,
            'empresa_id' => $bovedaCiclo->empresa_id,
            'fecha_boveda' => $bovedaCiclo->fecha,
        ]);
    }
}
