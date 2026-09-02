<?php

namespace Database\Factories;

use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuotaCredito>
 */
class CuotaCreditoFactory extends Factory
{
    protected $model = CuotaCredito::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $credito = Credito::factory()->create();

        return [
            'credito_id' => $credito->id,
            'empresa_id' => $credito->empresa_id,
            'numero_cuota' => 1,
            'fecha_vencimiento' => now()->addDays(30)->toDateString(),
            'monto_capital' => 1000,
            'monto_interes' => 100,
            'monto_total' => 1100,
        ];
    }

    /**
     * Attach this cuota to the given crédito.
     */
    public function paraCredito(Credito $credito): static
    {
        return $this->state(fn (): array => [
            'credito_id' => $credito->id,
            'empresa_id' => $credito->empresa_id,
        ]);
    }
}
