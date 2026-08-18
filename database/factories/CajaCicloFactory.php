<?php

namespace Database\Factories;

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CajaCiclo>
 */
class CajaCicloFactory extends Factory
{
    protected $model = CajaCiclo::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $caja = Caja::factory()->create();

        return [
            'caja_id' => $caja->id,
            'empresa_id' => $caja->empresa_id,
            'fecha' => now()->toDateString(),
            'estado' => 'abierta',
            'saldo_apertura' => 0,
            'abierta_at' => now(),
        ];
    }

    /**
     * Attach this ciclo to the given caja.
     */
    public function paraCaja(Caja $caja): static
    {
        return $this->state(fn (): array => [
            'caja_id' => $caja->id,
            'empresa_id' => $caja->empresa_id,
        ]);
    }

    /**
     * Mark the ciclo as cerrada.
     */
    public function cerrado(): static
    {
        return $this->state(fn (): array => [
            'estado' => 'cerrada',
            'saldo_calculado_cierre' => 0,
            'saldo_arqueo_cierre' => 0,
            'diferencia' => 0,
            'cerrada_at' => now(),
        ]);
    }
}
