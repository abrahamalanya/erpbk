<?php

namespace Database\Factories;

use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfiguracionCredito>
 */
class ConfiguracionCreditoFactory extends Factory
{
    protected $model = ConfiguracionCredito::class;

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
            'tipo_credito' => 'prendario',
            'interes_default' => fake()->randomFloat(2, 5, 20),
            'plazo_dias' => 30,
            'dias_espera_mora' => 15,
            'dias_minimo_interes' => 15,
            'tasa_mora_diaria' => fake()->randomFloat(2, 0.5, 2),
            'max_refrendos' => null,
        ];
    }

    /**
     * Default (empresa-wide) config for the given empresa.
     */
    public function deEmpresa(Empresa $empresa): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $empresa->id,
            'agencia_id' => null,
        ]);
    }

    /**
     * Agencia-specific override.
     */
    public function deAgencia(Agencia $agencia): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $agencia->empresa_id,
            'agencia_id' => $agencia->id,
        ]);
    }
}
