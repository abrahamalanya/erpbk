<?php

namespace App\Modules\Caja\Models;

use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\BovedaMovimientoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BovedaMovimiento extends Model
{
    /** @use HasFactory<BovedaMovimientoFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'boveda_ciclo_id',
        'empresa_id',
        'tipo',
        'monto',
        'concepto',
        'billetaje_id',
        'caja_ciclo_id',
        'registrado_por',
        'fecha_boveda',
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
            'fecha_boveda' => 'date',
        ];
    }

    public function bovedaCiclo(): BelongsTo
    {
        return $this->belongsTo(BovedaCiclo::class);
    }

    public function billetaje(): BelongsTo
    {
        return $this->belongsTo(Billetaje::class);
    }

    public function cajaCiclo(): BelongsTo
    {
        return $this->belongsTo(CajaCiclo::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    protected static function newFactory(): BovedaMovimientoFactory
    {
        return BovedaMovimientoFactory::new();
    }
}
