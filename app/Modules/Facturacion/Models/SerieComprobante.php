<?php

namespace App\Modules\Facturacion\Models;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\SerieComprobanteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una serie autorizada por SUNAT y su contador de correlativos.
 *
 * `ultimo_correlativo` es lo que garantiza la numeración sin huecos: se
 * incrementa con `lockForUpdate` dentro de la misma transacción que inserta el
 * comprobante, y NUNCA se libera (FACTURACION.md §5.4). Un número consumido
 * que se libera produce un hueco, y un hueco invalida la operación ante SUNAT.
 */
class SerieComprobante extends Model
{
    /** @use HasFactory<SerieComprobanteFactory> */
    use BelongsToTenant, HasFactory;

    // El plural de "serie" en español es "series", pero Eloquent derivaría
    // "serie_comprobantes". Mismo criterio que ConfiguracionVenta con
    // "configuraciones_venta".
    protected $table = 'series_comprobantes';

    /** @var list<string> */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'tipo_documento',
        'serie',
        'descripcion',
        'ultimo_correlativo',
        'limite_numeracion',
        'estado',
        'creado_por',
        'creado_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ultimo_correlativo' => 'integer',
            'limite_numeracion' => 'integer',
            'estado' => 'boolean',
            'creado_at' => 'datetime',
        ];
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class, 'series_comprobante_id');
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * ¿Quedan correlativos disponibles según el rango que autorizó SUNAT?
     * Un `limite_numeracion` nulo significa "sin tope informado".
     */
    public function tieneCupo(): bool
    {
        return $this->limite_numeracion === null
            || $this->ultimo_correlativo < $this->limite_numeracion;
    }

    protected static function newFactory(): SerieComprobanteFactory
    {
        return SerieComprobanteFactory::new();
    }
}
