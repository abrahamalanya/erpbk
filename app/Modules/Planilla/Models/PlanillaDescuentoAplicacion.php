<?php

namespace App\Modules\Planilla\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rastro de qué descuento se aplicó a qué línea de planilla, y por cuánto. Lo
 * escribe PlanillaService::cerrar() al consumir los descuentos y lo borra
 * reabrir(): es lo que hace exacta (y auditable) la reversión del descuento.
 */
class PlanillaDescuentoAplicacion extends Model
{
    /**
     * Plural explicito: sin esto Laravel deduciria `planilla_descuento_aplicacions`
     * del nombre de la clase y no encontraria la tabla
     * `planilla_descuento_aplicaciones`.
     */
    protected $table = 'planilla_descuento_aplicaciones';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'planilla_id',
        'planilla_trabajador_id',
        'planilla_descuento_id',
        'monto',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
        ];
    }

    public function planilla(): BelongsTo
    {
        return $this->belongsTo(Planilla::class);
    }

    public function linea(): BelongsTo
    {
        return $this->belongsTo(PlanillaTrabajador::class, 'planilla_trabajador_id');
    }

    public function descuento(): BelongsTo
    {
        return $this->belongsTo(PlanillaDescuento::class, 'planilla_descuento_id');
    }
}
