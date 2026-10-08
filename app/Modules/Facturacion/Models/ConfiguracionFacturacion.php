<?php

namespace App\Modules\Facturacion\Models;

use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\ConfiguracionFacturacionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parámetros de facturación de una empresa (una fila por empresa).
 *
 * `interes_incluye_igv` es el campo que decide cómo se desglosa el
 * comprobante de un crédito: en una financiera el interés que paga el cliente
 * normalmente ya trae el IGV adentro, así que hay que dividirlo entre 1.18;
 * si la empresa cobra sin IGV, el impuesto se suma encima.
 */
class ConfiguracionFacturacion extends Model
{
    /** @use HasFactory<ConfiguracionFacturacionFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'configuraciones_facturacion';

    /** @var list<string> */
    protected $fillable = [
        'empresa_id',
        'modo',
        'igv_porcentaje',
        'moneda',
        'interes_incluye_igv',
        'serie_factura_defecto',
        'serie_boleta_defecto',
        'bloquear_si_supera_umbral',
        'activo',
        'actualizado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'igv_porcentaje' => 'decimal:2',
            'interes_incluye_igv' => 'boolean',
            'bloquear_si_supera_umbral' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function estaEnProduccion(): bool
    {
        return $this->modo === 'produccion';
    }

    protected static function newFactory(): ConfiguracionFacturacionFactory
    {
        return ConfiguracionFacturacionFactory::new();
    }
}
