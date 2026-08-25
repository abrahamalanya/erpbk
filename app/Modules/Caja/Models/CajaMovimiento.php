<?php

namespace App\Modules\Caja\Models;

use App\Modules\Sistemas\Models\Concepto;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CajaMovimientoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CajaMovimiento extends Model
{
    /** @use HasFactory<CajaMovimientoFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'caja_ciclo_id',
        'empresa_id',
        'tipo',
        'monto',
        'medio',
        'canal',
        'concepto',
        'descripcion',
        'concepto_id',
        'billetaje_id',
        'registrado_por',
        'fecha_caja',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_caja' => 'date',
        ];
    }

    public function cajaCiclo(): BelongsTo
    {
        return $this->belongsTo(CajaCiclo::class);
    }

    public function billetaje(): BelongsTo
    {
        return $this->belongsTo(Billetaje::class);
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(Concepto::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function fotos(): MorphMany
    {
        return $this->morphMany(MovimientoFoto::class, 'fotografiable');
    }

    protected static function newFactory(): CajaMovimientoFactory
    {
        return CajaMovimientoFactory::new();
    }
}
