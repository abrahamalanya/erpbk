<?php

namespace App\Modules\Cobranza\Models;

use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CobroFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un cobro registrado sobre un crédito (refrendo, adenda, liquidación, pago
 * de cuota de un compuesto, pago de una o varias cuotas de un diario, o
 * refinanciamiento). Lo crea CreditoService al recibir el pago; el módulo
 * Cobranzas lo lista y permite anularlo (ver CreditoService::anularCobro())
 * mientras el ciclo de caja donde se cobró siga abierto.
 */
class Cobro extends Model
{
    /** @use HasFactory<CobroFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'cliente_id',
        'credito_id',
        'credito_sucesor_id',
        'caja_ciclo_id',
        'caja_movimiento_id',
        'registrado_por',
        'operacion',
        'estado',
        'credito_estado_anterior',
        'monto_pagado',
        'medio',
        'interes',
        'mora',
        'descuento',
        'motivo_descuento',
        'vuelto',
        'anulado_por',
        'anulado_at',
        'motivo_anulacion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_pagado' => 'decimal:2',
            'interes' => 'decimal:2',
            'mora' => 'decimal:2',
            'descuento' => 'decimal:2',
            'vuelto' => 'decimal:2',
            'anulado_at' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function creditoSucesor(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_sucesor_id');
    }

    public function cuotasPagadas(): HasMany
    {
        return $this->hasMany(CuotaCredito::class, 'cobro_id');
    }

    public function abonosCuotas(): HasMany
    {
        return $this->hasMany(CobroCuotaAbono::class);
    }

    public function cajaCiclo(): BelongsTo
    {
        return $this->belongsTo(CajaCiclo::class);
    }

    public function cajaMovimiento(): BelongsTo
    {
        return $this->belongsTo(CajaMovimiento::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    protected static function newFactory(): CobroFactory
    {
        return CobroFactory::new();
    }
}
