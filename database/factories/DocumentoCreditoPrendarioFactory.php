<?php

namespace Database\Factories;

use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\CreditoPrendario\Models\DocumentoCreditoPrendario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentoCreditoPrendario>
 */
class DocumentoCreditoPrendarioFactory extends Factory
{
    protected $model = DocumentoCreditoPrendario::class;

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
            'tipo' => 'contrato',
            'generado_por' => $credito->registrado_por,
            'generado_at' => now(),
        ];
    }

    /**
     * Attach this documento to the given crédito.
     */
    public function paraCredito(CreditoPrendario $credito): static
    {
        return $this->state(fn (): array => [
            'credito_id' => $credito->id,
            'empresa_id' => $credito->empresa_id,
        ]);
    }
}
