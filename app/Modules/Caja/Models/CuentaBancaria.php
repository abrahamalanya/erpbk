<?php

namespace App\Modules\Caja\Models;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use App\Nucleo\Models\Banco;
use Database\Factories\CuentaBancariaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CuentaBancaria extends Model
{
    /** @use HasFactory<CuentaBancariaFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Eloquent's default table-name guess only pluralizes the last word
     * ("cuenta_bancarias"), not the real table ("cuentas_bancarias") — same
     * class of Spanish-pluralization mismatch documented for apiResource
     * route-model-binding elsewhere in this project.
     */
    protected $table = 'cuentas_bancarias';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'boveda_id',
        'empresa_id',
        'banco_id',
        'numero_cuenta',
        'titular',
        'tipo_cuenta',
        'moneda',
        'alias',
        'activa',
        'acepta_yape',
        'numero_yape',
        'acepta_plin',
        'numero_plin',
        'saldo_inicial',
        'creada_por',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'acepta_yape' => 'boolean',
            'acepta_plin' => 'boolean',
            'saldo_inicial' => 'decimal:2',
        ];
    }

    public function boveda(): BelongsTo
    {
        return $this->belongsTo(Boveda::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function banco(): BelongsTo
    {
        return $this->belongsTo(Banco::class);
    }

    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(CuentaBancariaMovimiento::class);
    }

    public function conciliaciones(): HasMany
    {
        return $this->hasMany(ConciliacionBancaria::class);
    }

    /**
     * Live balance: saldo_inicial plus every ingreso minus every egreso
     * movimiento so far — never stored, always recomputed from the
     * movimientos, same pattern as CajaCiclo::saldoActual()/BovedaCiclo::saldoActual().
     */
    public function saldoActual(): string
    {
        $ingresos = (string) $this->movimientos()->where('tipo', 'ingreso')->sum('monto');
        $egresos = (string) $this->movimientos()->where('tipo', 'egreso')->sum('monto');

        return bcadd($this->saldo_inicial, bcsub($ingresos, $egresos, 2), 2);
    }

    protected static function newFactory(): CuentaBancariaFactory
    {
        return CuentaBancariaFactory::new();
    }
}
