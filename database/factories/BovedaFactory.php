<?php

namespace Database\Factories;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Boveda>
 */
class BovedaFactory extends Factory
{
    protected $model = Boveda::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'agencia_id' => null,
            'tipo' => 'principal',
        ];
    }

    /**
     * Mark this as the principal bóveda of the given empresa.
     */
    public function principalDe(Empresa $empresa): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $empresa->id,
            'agencia_id' => null,
            'tipo' => 'principal',
        ]);
    }

    /**
     * Mark this as the bóveda of the given agencia.
     */
    public function deAgencia(Agencia $agencia): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $agencia->empresa_id,
            'agencia_id' => $agencia->id,
            'tipo' => 'agencia',
        ]);
    }
}
