<?php

namespace Database\Factories;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\BovedaCiclo;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BovedaCiclo>
 */
class BovedaCicloFactory extends Factory
{
    protected $model = BovedaCiclo::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'boveda_id' => Boveda::factory(),
            'empresa_id' => Empresa::factory(),
            'fecha' => now()->toDateString(),
            'estado' => 'abierta',
            'saldo_apertura' => 0,
            'abierta_por' => User::factory(),
            'abierta_at' => now(),
        ];
    }

    /**
     * Attach this ciclo to the given bóveda.
     */
    public function paraBoveda(Boveda $boveda): static
    {
        return $this->state(fn (): array => [
            'boveda_id' => $boveda->id,
            'empresa_id' => $boveda->empresa_id,
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
            'cerrada_por' => User::factory(),
            'cerrada_at' => now(),
        ]);
    }
}
