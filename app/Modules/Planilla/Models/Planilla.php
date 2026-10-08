<?php

namespace App\Modules\Planilla\Models;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\PlanillaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabecera de la planilla de un periodo (empresa + agencia + 'YYYY-MM').
 * Los totales se congelan al confirmar para que el reporte de un mes liquidado
 * no cambie si más adelante se editan tasas o descuentos.
 */
class Planilla extends Model
{
    /** @use HasFactory<PlanillaFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'periodo',
        'fecha_pago',
        'estado',
        'total_ingresos',
        'total_descuentos',
        'total_tributos_trabajador',
        'total_neto',
        'total_aportes_empleador',
        'registrado_por',
        'cerrada_por',
        'cerrada_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_pago' => 'date',
            'cerrada_at' => 'datetime',
            'total_ingresos' => 'decimal:2',
            'total_descuentos' => 'decimal:2',
            'total_tributos_trabajador' => 'decimal:2',
            'total_neto' => 'decimal:2',
            'total_aportes_empleador' => 'decimal:2',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function trabajadores(): HasMany
    {
        return $this->hasMany(PlanillaTrabajador::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function cerradaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    /**
     * El periodo es único por (empresa, agencia). Como agencia_id admite NULL,
     * un índice único de BD no alcanza para bloquear dos planillas de empresa
     * completa del mismo mes — lo resuelve PlanillaService::crear() con un
     * whereNull() explícito, que sí funciona en MySQL y en SQLite.
     */
    public function esBorrador(): bool
    {
        return $this->estado === 'borrador';
    }

    protected static function newFactory(): PlanillaFactory
    {
        return PlanillaFactory::new();
    }
}
