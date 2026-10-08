<?php

namespace Database\Factories;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\SerieComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerieComprobante>
 */
class SerieComprobanteFactory extends Factory
{
    protected $model = SerieComprobante::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tipo = fake()->randomElement([TipoComprobante::Factura, TipoComprobante::Boleta]);

        return [
            'empresa_id' => Empresa::factory(),
            'agencia_id' => null,
            'tipo_documento' => $tipo->value,
            'serie' => $tipo === TipoComprobante::Factura ? 'F001' : 'B001',
            'descripcion' => $tipo->denominacion(),
            'ultimo_correlativo' => 0,
            'limite_numeracion' => null,
            'estado' => true,
        ];
    }

    public function deEmpresa(Empresa $empresa): static
    {
        return $this->state(fn (): array => ['empresa_id' => $empresa->id]);
    }

    public function deAgencia(Agencia $agencia): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $agencia->empresa_id,
            'agencia_id' => $agencia->id,
        ]);
    }

    public function factura(string $serie = 'F001'): static
    {
        return $this->state(fn (): array => [
            'tipo_documento' => TipoComprobante::Factura->value,
            'serie' => $serie,
            'descripcion' => TipoComprobante::Factura->denominacion(),
        ]);
    }

    public function boleta(string $serie = 'B001'): static
    {
        return $this->state(fn (): array => [
            'tipo_documento' => TipoComprobante::Boleta->value,
            'serie' => $serie,
            'descripcion' => TipoComprobante::Boleta->denominacion(),
        ]);
    }

    public function conCorrelativo(int $ultimo): static
    {
        return $this->state(fn (): array => ['ultimo_correlativo' => $ultimo]);
    }

    /**
     * Serie que ya agotó el rango que autorizó SUNAT.
     */
    public function sinCupo(): static
    {
        return $this->state(fn (): array => [
            'ultimo_correlativo' => 100,
            'limite_numeracion' => 100,
        ]);
    }

    public function inactiva(): static
    {
        return $this->state(fn (): array => ['estado' => false]);
    }
}
