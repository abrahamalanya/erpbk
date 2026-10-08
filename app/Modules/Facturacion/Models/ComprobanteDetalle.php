<?php

namespace App\Modules\Facturacion\Models;

use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Facturacion\Tipos\CategoriaTributo;
use App\Nucleo\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del detalle de un comprobante. El XML exige el importe de IGV por
 * línea (`cac:InvoiceLine/cac:TaxTotal`), además del total del documento.
 */
class ComprobanteDetalle extends Model
{
    use BelongsToTenant;

    protected $table = 'comprobante_detalles';

    /** @var list<string> */
    protected $fillable = [
        'comprobante_id',
        'empresa_id',
        'orden',
        'codigo_producto',
        'descripcion',
        'unidad_medida',
        'cantidad',
        'valor_unitario',
        'precio_unitario',
        'descuento',
        'valor_venta',
        'igv',
        'total',
        'categoria_tributo',
        'porcentaje_igv',
        'cobro_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'cantidad' => 'decimal:4',
            'valor_unitario' => 'decimal:4',
            'precio_unitario' => 'decimal:4',
            'descuento' => 'decimal:2',
            'valor_venta' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
            'categoria_tributo' => CategoriaTributo::class,
            'porcentaje_igv' => 'decimal:2',
        ];
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }

    /**
     * Cobro del crédito que originó esta línea, para poder auditar de dónde
     * salió el concepto facturado.
     */
    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }
}
