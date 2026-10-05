<?php

namespace App\Modules\Cliente\Models;

use Database\Factories\ClienteFotoFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Una foto del juego de imágenes múltiples de un cliente (casa, negocio o
 * adicionales). Se modela como una fila por archivo —igual que
 * GarantiaFoto y MovimientoFoto— en vez de columnas string o un JSON de rutas,
 * para poder borrar una foto suelta y conservar el orden.
 */
class ClienteFoto extends Model
{
    /** @use HasFactory<ClienteFotoFactory> */
    use HasFactory;

    public const TIPO_CASA = 'casa';

    public const TIPO_NEGOCIO = 'negocio';

    public const TIPO_ADICIONALES = 'adicionales';

    /**
     * @var list<string>
     */
    public const TIPOS = [self::TIPO_CASA, self::TIPO_NEGOCIO, self::TIPO_ADICIONALES];

    /** Tope de imágenes por tipo, alineado con el `max:10` que usa Bien/Vehiculo. */
    public const MAX_POR_TIPO = 10;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cliente_id',
        'tipo',
        'path',
        'orden',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['url'];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk('public')->url($this->path));
    }

    protected static function newFactory(): ClienteFotoFactory
    {
        return ClienteFotoFactory::new();
    }
}
