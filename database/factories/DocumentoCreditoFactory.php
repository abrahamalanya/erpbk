<?php

namespace Database\Factories;

use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\DocumentoCredito;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentoCredito>
 */
class DocumentoCreditoFactory extends Factory
{
    protected $model = DocumentoCredito::class;

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
            'tipo' => 'contrato',
            'generado_por' => $credito->registrado_por,
            'generado_at' => now(),
        ];
    }

    /**
     * Attach this documento to the given crédito.
     */
    public function paraCredito(Credito $credito): static
    {
        return $this->state(fn (): array => [
            'credito_id' => $credito->id,
            'empresa_id' => $credito->empresa_id,
        ]);
    }
}
