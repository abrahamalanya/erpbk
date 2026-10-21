<?php

namespace App\Modules\Ubicacion\Models;

use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial append-only de ubicaciones GPS de un asesor — una fila por cada
 * ping válido (ver migración), a diferencia de `UbicacionAsesor` que solo
 * guarda la última posición.
 */
class UbicacionAsesorHistorial extends Model
{
    const UPDATED_AT = null;

    protected $table = 'ubicacion_asesor_historial';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'latitud',
        'longitud',
        'precision_metros',
        'velocidad_ms',
        'capturado_en',
        'empresa_id',
        'agencia_id',
    ];

    protected function casts(): array
    {
        return [
            'latitud' => 'float',
            'longitud' => 'float',
            'precision_metros' => 'float',
            'velocidad_ms' => 'float',
            'capturado_en' => 'datetime',
        ];
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
