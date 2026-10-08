<?php

namespace App\Modules\Planilla\Models;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\PlanillaTrabajadorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea de planilla por trabajador. Todos los importes (del trabajador y del
 * empleador) se calculan y persisten al confirmar — ver PlanillaService — de
 * modo que el reporte histórico no se altera si mañana cambian las tasas
 * configuradas.
 */
class PlanillaTrabajador extends Model
{
    /** @use HasFactory<PlanillaTrabajadorFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Plural explícito: sin esto Laravel deduciría `planilla_trabajadors` del
     * nombre de la clase y no encontraría la tabla `planilla_trabajadores`.
     */
    protected $table = 'planilla_trabajadores';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'planilla_id',
        'empresa_id',
        'agencia_id',
        'user_id',
        'tipo_documento',
        'numero_documento',
        'apellidos',
        'nombres',
        'situacion',
        'ingresos',
        'descuentos_devengado',
        'descuentos_pagado',
        'onp',
        'afp',
        'essalud_trabajador',
        'tributos_trabajador',
        'essalud_empleador',
        'cts',
        'aportes_empleador',
        'neto',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ingresos' => 'decimal:2',
            'descuentos_devengado' => 'decimal:2',
            'descuentos_pagado' => 'decimal:2',
            'onp' => 'decimal:2',
            'afp' => 'decimal:2',
            'essalud_trabajador' => 'decimal:2',
            'tributos_trabajador' => 'decimal:2',
            'essalud_empleador' => 'decimal:2',
            'cts' => 'decimal:2',
            'aportes_empleador' => 'decimal:2',
            'neto' => 'decimal:2',
        ];
    }

    public function planilla(): BelongsTo
    {
        return $this->belongsTo(Planilla::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Neto a pagar del mes: ingresos - descuentos pagados de este mes - tributos
     * del trabajador. Se persiste en `neto` al confirmar; este método es la
     * misma fórmula para que el preview del borrador y la línea cerrada no
     * puedan divergir.
     */
    public function netoCalculado(): string
    {
        return bcsub(
            bcsub($this->ingresos, $this->descuentos_pagado, 2),
            $this->tributos_trabajador,
            2,
        );
    }

    protected static function newFactory(): PlanillaTrabajadorFactory
    {
        return PlanillaTrabajadorFactory::new();
    }
}
