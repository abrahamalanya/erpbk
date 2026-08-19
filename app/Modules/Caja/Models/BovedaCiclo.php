<?php

namespace App\Modules\Caja\Models;

use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\BovedaCicloFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BovedaCiclo extends Model
{
    /** @use HasFactory<BovedaCicloFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'boveda_id',
        'empresa_id',
        'fecha',
        'estado',
        'saldo_apertura',
        'saldo_calculado_cierre',
        'saldo_arqueo_cierre',
        'diferencia',
        'abierta_por',
        'cerrada_por',
        'abierta_at',
        'cerrada_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'saldo_apertura' => 'decimal:2',
            'saldo_calculado_cierre' => 'decimal:2',
            'saldo_arqueo_cierre' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'abierta_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }

    public function boveda(): BelongsTo
    {
        return $this->belongsTo(Boveda::class);
    }

    public function abiertaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'abierta_por');
    }

    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(BovedaMovimiento::class);
    }

    /**
     * Live balance: saldo_apertura plus every ingreso minus every egreso
     * movimiento so far — never stored, always recomputed from the
     * movimientos so it can't drift out of sync with them.
     */
    public function saldoActual(): string
    {
        $ingresos = (string) $this->movimientos()->where('tipo', 'ingreso')->sum('monto');
        $egresos = (string) $this->movimientos()->where('tipo', 'egreso')->sum('monto');

        return bcadd($this->saldo_apertura, bcsub($ingresos, $egresos, 2), 2);
    }

    protected static function newFactory(): BovedaCicloFactory
    {
        return BovedaCicloFactory::new();
    }
}
