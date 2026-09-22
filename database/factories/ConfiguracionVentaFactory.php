<?php

namespace Database\Factories;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Venta\Models\ConfiguracionVenta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfiguracionVenta>
 */
class ConfiguracionVentaFactory extends Factory
{
    protected $model = ConfiguracionVenta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'agencia_id' => null,
            'interes_mensual_default' => fake()->randomFloat(2, 0, 5),
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
