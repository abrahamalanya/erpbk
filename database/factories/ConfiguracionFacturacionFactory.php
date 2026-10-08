<?php

namespace Database\Factories;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConfiguracionFacturacion>
 */
class ConfiguracionFacturacionFactory extends Factory
{
    protected $model = ConfiguracionFacturacion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'modo' => 'beta',
            'igv_porcentaje' => 18.00,
            'moneda' => 'PEN',
            'interes_incluye_igv' => true,
            'serie_factura_defecto' => 'F001',
            'serie_boleta_defecto' => 'B001',
            'bloquear_si_supera_umbral' => false,
            'activo' => false,
        ];
    }

    public function deEmpresa(Empresa $empresa): static
    {
        return $this->state(fn (): array => ['empresa_id' => $empresa->id]);
    }

    /**
     * Empresa que cobra el interés sin IGV adentro: el impuesto se suma encima
     * en vez de extraerse.
     */
    public function sinIgvEnElInteres(): static
    {
        return $this->state(fn (): array => ['interes_incluye_igv' => false]);
    }

    public function enProduccion(): static
    {
        return $this->state(fn (): array => ['modo' => 'produccion']);
    }

    public function activa(): static
    {
        return $this->state(fn (): array => ['activo' => true]);
    }
}
