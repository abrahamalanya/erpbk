<?php

namespace Database\Factories;

use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\CreditoPrendario\Models\CuotaCreditoPrendario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuotaCreditoPrendario>
 */
class CuotaCreditoPrendarioFactory extends Factory
{
    protected $model = CuotaCreditoPrendario::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $credito = CreditoPrendario::factory()->create();

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
    public function paraCredito(CreditoPrendario $credito): static
    {
        return $this->state(fn (): array => [
            'credito_id' => $credito->id,
            'empresa_id' => $credito->empresa_id,
        ]);
    }
}
