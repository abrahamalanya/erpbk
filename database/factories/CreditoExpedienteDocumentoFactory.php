<?php

namespace Database\Factories;

use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CreditoExpedienteDocumento;
use App\Modules\Empresa\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditoExpedienteDocumento>
 */
class CreditoExpedienteDocumentoFactory extends Factory
{
    protected $model = CreditoExpedienteDocumento::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'credito_id' => Credito::factory(),
            'subido_por' => null,
            'rol' => 'deudor',
            'seccion' => 'terreno',
            'path' => 'credito-expediente/fake/'.fake()->uuid().'.jpg',
            'orden' => 0,
        ];
    }

    public function paraCredito(Credito $credito): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $credito->empresa_id,
            'credito_id' => $credito->id,
        ]);
    }
}
