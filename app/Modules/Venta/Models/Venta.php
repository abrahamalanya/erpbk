<?php

namespace App\Modules\Venta\Models;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\Credito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\VentaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * La venta de un artículo de tienda (bien/vehículo/inmueble en estado
 * disponible_venta) a un cliente comprador, en una de tres modalidades
 * (forma_venta: contado, credito, apartado). Independiente del ciclo de
 * vida de Credito — ver App\Modules\Venta\Services\VentaService.
 */
class Venta extends Model
{
    /** @use HasFactory<VentaFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'articulo_type',
        'articulo_id',
        'credito_origen_id',
        'cliente_id',
        'vendido_por',
        'forma_venta',
        'estado',
        'precio_venta',
        'inicial',
        'interes',
        'numero_cuotas',
        'fecha_limite',
        'saldo_pendiente',
        'pagada_at',
        'cancelada_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'precio_venta' => 'decimal:2',
            'inicial' => 'decimal:2',
            'interes' => 'decimal:2',
            'saldo_pendiente' => 'decimal:2',
            'fecha_limite' => 'date',
            'pagada_at' => 'datetime',
            'cancelada_at' => 'datetime',
        ];
    }

    public function articulo(): MorphTo
    {
        return $this->morphTo();
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function creditoOrigen(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_origen_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function vendidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendido_por');
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(CuotaVenta::class)->orderBy('numero_cuota');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoVenta::class);
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoVenta::class);
    }

    protected static function newFactory(): VentaFactory
    {
        return VentaFactory::new();
    }
}
