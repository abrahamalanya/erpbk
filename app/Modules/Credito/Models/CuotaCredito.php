<?php

namespace App\Modules\Credito\Models;

use App\Modules\Cobranza\Models\Cobro;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CuotaCreditoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuotaCredito extends Model
{
    /** @use HasFactory<CuotaCreditoFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cuotas_credito_prendario';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'credito_id',
        'empresa_id',
        'numero_cuota',
        'fecha_vencimiento',
        'monto_capital',
        'monto_interes',
        'monto_total',
        'monto_abonado',
        'pagada_at',
        'mora_pagada',
        'cobro_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_vencimiento' => 'date',
            'monto_capital' => 'decimal:2',
            'monto_interes' => 'decimal:2',
            'monto_total' => 'decimal:2',
            'monto_abonado' => 'decimal:2',
            'pagada_at' => 'datetime',
            'mora_pagada' => 'decimal:2',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->whereNull('pagada_at');
    }

    public function scopePagadas(Builder $query): Builder
    {
        return $query->whereNotNull('pagada_at');
    }

    protected static function newFactory(): CuotaCreditoFactory
    {
        return CuotaCreditoFactory::new();
    }
}
