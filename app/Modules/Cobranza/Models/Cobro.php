<?php

namespace App\Modules\Cobranza\Models;

use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\Credito;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CobroFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cobro registrado sobre un crédito (refrendo, adenda o liquidación).
 * Lo crea CreditoService al recibir el pago; el módulo Cobranzas solo lo
 * lista. El pago en sí (movimiento de caja, cambio de estado del crédito)
 * lo siguen manejando refrendar()/adendar()/liquidar().
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
        'registrado_por',
        'operacion',
        'monto_pagado',
        'medio',
        'interes',
        'mora',
        'descuento',
        'motivo_descuento',
        'vuelto',
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

    public function cajaCiclo(): BelongsTo
    {
        return $this->belongsTo(CajaCiclo::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    protected static function newFactory(): CobroFactory
    {
        return CobroFactory::new();
    }
}
