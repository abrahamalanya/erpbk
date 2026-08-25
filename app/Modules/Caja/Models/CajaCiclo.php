<?php

namespace App\Modules\Caja\Models;

use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CajaCicloFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CajaCiclo extends Model
{
    /** @use HasFactory<CajaCicloFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'caja_id',
        'empresa_id',
        'fecha',
        'estado',
        'saldo_apertura',
        'saldo_calculado_cierre',
        'saldo_efectivo_cierre',
        'saldo_arqueo_cierre',
        'diferencia',
        'cerrada_por',
        'cierre_forzado',
        'cierre_automatico',
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
            'saldo_efectivo_cierre' => 'decimal:2',
            'saldo_arqueo_cierre' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'cierre_forzado' => 'boolean',
            'cierre_automatico' => 'boolean',
            'abierta_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(CajaMovimiento::class);
    }

    public function billetajes(): HasMany
    {
        return $this->hasMany(Billetaje::class);
    }

    /**
     * Live balance: saldo_apertura plus every ingreso/billetaje minus every
     * egreso movimiento so far — never stored, always recomputed from the
     * movimientos so it can't drift out of sync with them.
     */
    public function saldoActual(): string
    {
        $ingresos = (string) $this->movimientos()->whereIn('tipo', ['ingreso', 'billetaje'])->sum('monto');
        $egresos = (string) $this->movimientos()->where('tipo', 'egreso')->sum('monto');

        return bcadd($this->saldo_apertura, bcsub($ingresos, $egresos, 2), 2);
    }

    /**
     * Physical-cash-only balance: same as saldoActual(), but ingresos/
     * billetajes handed off digitally (yape/plin/transferencia — see
     * BilletajeService::aprobarPorCuentaBancaria()) are excluded, since that
     * money never became cash in the actor's hand. This is what the cierre
     * screen compares monto_contado against; saldoActual() stays the "can I
     * afford this" check for desembolsar/registrarMovimiento, since digital
     * billetaje is still real money the actor has to work with, just not
     * physical cash.
     *
     * Floored at zero: every disbursement/gasto this app models is recorded
     * as a plain egreso with no medio of its own (it's always assumed to be
     * a physical hand-off), so once digital billetaje funds more spending
     * than the actor ever received in physical cash, the naive cash-only
     * subtraction would go negative — nonsensical for "cash you should have
     * on hand". Once egresos exceed cash-in, the excess is treated as having
     * been paid out of the digital portion (already reflected correctly in
     * saldoActual()); the physical-cash expectation simply bottoms out at 0.
     */
    public function saldoEfectivo(): string
    {
        $ingresos = (string) $this->movimientos()
            ->whereIn('tipo', ['ingreso', 'billetaje'])
            ->where('medio', 'efectivo')
            ->sum('monto');
        $egresos = (string) $this->movimientos()->where('tipo', 'egreso')->sum('monto');

        $saldo = bcadd($this->saldo_apertura, bcsub($ingresos, $egresos, 2), 2);

        return bccomp($saldo, '0', 2) < 0 ? '0.00' : $saldo;
    }

    protected static function newFactory(): CajaCicloFactory
    {
        return CajaCicloFactory::new();
    }
}
