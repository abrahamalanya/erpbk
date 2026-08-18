<?php

namespace Database\Factories;

use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CajaMovimiento>
 */
class CajaMovimientoFactory extends Factory
{
    protected $model = CajaMovimiento::class;

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
            'empresa_id' => $cajaCiclo->empresa_id,
            'tipo' => 'ingreso',
            'monto' => fake()->randomFloat(2, 10, 500),
            'concepto' => fake()->sentence(3),
            'registrado_por' => $cajaCiclo->caja->user_id,
            'fecha_caja' => $cajaCiclo->fecha,
        ];
    }

    /**
     * Attach this movimiento to the given ciclo.
     */
    public function paraCiclo(CajaCiclo $cajaCiclo): static
    {
        return $this->state(fn (): array => [
            'caja_ciclo_id' => $cajaCiclo->id,
            'empresa_id' => $cajaCiclo->empresa_id,
            'fecha_caja' => $cajaCiclo->fecha,
        ]);
    }
}
