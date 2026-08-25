<?php

namespace App\Modules\Caja\Models;

use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\BilletajeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Billetaje extends Model
{
    /** @use HasFactory<BilletajeFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'caja_ciclo_id',
        'boveda_id',
        'empresa_id',
        'monto',
        'estado',
        'motivo',
        'medio_recepcion',
        'datos_recepcion',
        'solicitado_por',
        'aprobado_por',
        'motivo_rechazo',
        'medio_egreso',
        'canal_egreso',
        'cuenta_bancaria_id',
        'fecha_resolucion',
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
            'fecha_resolucion' => 'datetime',
        ];
    }

    public function cajaCiclo(): BelongsTo
    {
        return $this->belongsTo(CajaCiclo::class);
    }

    public function boveda(): BelongsTo
    {
        return $this->belongsTo(Boveda::class);
    }

    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class);
    }

    protected static function newFactory(): BilletajeFactory
    {
        return BilletajeFactory::new();
    }
}
