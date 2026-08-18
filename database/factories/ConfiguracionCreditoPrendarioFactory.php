<?php

namespace Database\Factories;

use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfiguracionCreditoPrendario>
 */
class ConfiguracionCreditoPrendarioFactory extends Factory
{
    protected $model = ConfiguracionCreditoPrendario::class;

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
            'tipo' => fake()->randomElement(['electro', 'varios']),
            'interes_default' => fake()->randomFloat(2, 5, 20),
            'plazo_dias' => 30,
            'dias_espera_mora' => 15,
            'tasa_mora_diaria' => fake()->randomFloat(2, 0.5, 2),
            'max_refrendos' => null,
        ];
    }

    /**
     * Default (empresa-wide) config for the given empresa/tipo.
     */
    public function deEmpresa(Empresa $empresa, string $tipo): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $empresa->id,
            'agencia_id' => null,
            'tipo' => $tipo,
        ]);
    }

    /**
     * Agencia-specific override.
     */
    public function deAgencia(Agencia $agencia, string $tipo): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $agencia->empresa_id,
            'agencia_id' => $agencia->id,
            'tipo' => $tipo,
        ]);
    }
}
