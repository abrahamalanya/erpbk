<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\SerieComprobante;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comprobante>
 */
class ComprobanteFactory extends Factory
{
    protected $model = Comprobante::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'agencia_id' => null,
            'cliente_id' => Cliente::factory(),
            'series_comprobante_id' => SerieComprobante::factory(),
            'tipo_documento' => TipoComprobante::Boleta->value,
            'serie' => 'B001',
            'correlativo' => 1,
            'numero' => 'B001-00000001',
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => now()->toTimeString(),
            'moneda' => 'PEN',
            'subtotal' => '84.75',
            'igv' => '15.26',
            'total' => '100.01',
            'importe_letras' => 'CIEN CON 01/100 SOLES',
            'porcentaje_igv' => '18.00',
            'estado' => EstadoComprobante::Generado->value,
            'emisor_ruc' => '20601030013',
            'emisor_razon_social' => 'EMPRESA DE PRUEBA S.A.C.',
            'receptor_tipo_documento' => 'dni',
            'receptor_numero_documento' => '12345678',
            'receptor_nombre' => 'JUAN PEREZ',
        ];
    }
}
