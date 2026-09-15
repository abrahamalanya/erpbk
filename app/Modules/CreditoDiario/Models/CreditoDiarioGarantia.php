<?php

namespace App\Modules\CreditoDiario\Models;

use App\Modules\Credito\Concerns\EsGarantia;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CreditoDiarioGarantiaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Garantía placeholder invisible para créditos diarios: no representa una
 * prenda real, solo satisface el motor de crédito compartido (CreditoService)
 * que asume que todo crédito tiene al menos una garantía adjunta. Nunca se
 * muestra al usuario.
 */
class CreditoDiarioGarantia extends Model
{
    /** @use HasFactory<CreditoDiarioGarantiaFactory> */
    use BelongsToTenant, EsGarantia, HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'creditos_diarios_garantias';

    /** Prefijo del código único de la garantía (ver EsGarantia::bootEsGarantia()). */
    public const CODIGO_PREFIJO = 'D';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'cliente_id',
        'registrado_por',
        'valorizacion',
        'precio_venta',
        'estado',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valorizacion' => 'decimal:2',
            'precio_venta' => 'decimal:2',
        ];
    }

    protected static function newFactory(): CreditoDiarioGarantiaFactory
    {
        return CreditoDiarioGarantiaFactory::new();
    }
}
