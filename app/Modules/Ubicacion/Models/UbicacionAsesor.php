<?php

namespace App\Modules\Ubicacion\Models;

use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Última ubicación GPS conocida de un asesor — una fila por asesor
 * (ver migración), sobrescrita en cada ping.
 */
class UbicacionAsesor extends Model
{
    protected $table = 'ubicaciones_asesores';

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
            'capturado_en' => 'datetime',
        ];
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
