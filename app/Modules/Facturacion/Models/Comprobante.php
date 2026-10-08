<?php

namespace App\Modules\Facturacion\Models;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\ComprobanteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un comprobante de pago electrónico (factura, boleta o nota de crédito).
 *
 * Los datos fiscales del emisor y del receptor (`emisor_*`, `receptor_*`) son
 * un **snapshot de la emisión**, no una referencia viva: el PDF se re-renderiza
 * al vuelo desde esta fila y tiene que mostrar exactamente lo que se firmó y
 * envió a SUNAT, aunque mañana se corrija el RUC del cliente.
 *
 * `serie` y `numero` aquí identifican el comprobante que la empresa EMITE. No
 * tienen relación con `caja_movimientos.serie` / `.numero`, que son la boleta de
 * un tercero que la empresa recibe.
 */
class Comprobante extends Model
{
    /** @use HasFactory<ComprobanteFactory> */
    use BelongsToTenant, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'cliente_id',
        'origen_tipo',
        'origen_id',
        'series_comprobante_id',
        'tipo_documento',
        'serie',
        'correlativo',
        'numero',
        'fecha_emision',
        'hora_emision',
        'moneda',
        'subtotal',
        'descuento_global',
        'igv',
        'total',
        'importe_letras',
        'porcentaje_igv',
        'forma_pago',
        'medio_pago',
        'codigo_detraccion',
        'estado',
        'observaciones',
        'emisor_ruc',
        'emisor_razon_social',
        'emisor_nombre_comercial',
        'emisor_domicilio',
        'emisor_ubigeo',
        'emisor_direccion_completa',
        'receptor_tipo_documento',
        'receptor_numero_documento',
        'receptor_nombre',
        'receptor_domicilio',
        'receptor_ubigeo',
        'receptor_email',
        'cdr_codigo',
        'cdr_descripcion',
        'codigo_seguridad',
        'hash_cdr',
        'cdr_digests',
        'enviado_at',
        'aceptado_at',
        'anulado_por',
        'anulado_at',
        'motivo_anulacion',
        'baja_comunicada_at',
        'documento_origen_id',
        'xml_path',
        'cdr_path',
        'zip_path',
        'generado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo_documento' => TipoComprobante::class,
            'estado' => EstadoComprobante::class,
            'correlativo' => 'integer',
            'subtotal' => 'decimal:2',
            'descuento_global' => 'decimal:2',
            'igv' => 'decimal:2',
            'total' => 'decimal:2',
            'porcentaje_igv' => 'decimal:2',
            'cdr_digests' => 'array',
            'fecha_emision' => 'date',
            'enviado_at' => 'datetime',
            'aceptado_at' => 'datetime',
            'anulado_at' => 'datetime',
            'baja_comunicada_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function serieComprobante(): BelongsTo
    {
        return $this->belongsTo(SerieComprobante::class, 'series_comprobante_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(ComprobanteDetalle::class)->orderBy('orden');
    }

    /**
     * Documento que este corrige (nota de crédito) o que se cancela con baja.
     */
    public function documentoOrigen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'documento_origen_id');
    }

    /**
     * ¿El número ya se gastó ante SUNAT y no se puede reutilizar?
     */
    public function numeroConsumido(): bool
    {
        return $this->estado->numeroConsumido();
    }

    public function esValido(): bool
    {
        return $this->estado->esValido();
    }

    protected static function newFactory(): ComprobanteFactory
    {
        return ComprobanteFactory::new();
    }

    /**
     * Formato que exige SUNAT en el nombre del archivo y en la impresión:
     * F001-00000123. El correlativo va con 8 dígitos de relleno.
     */
    public function numeroFormateado(): string
    {
        return $this->serie.'-'.str_pad((string) $this->correlativo, 8, '0', STR_PAD_LEFT);
    }
}
