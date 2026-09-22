<?php

namespace App\Modules\Venta\Models;

use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\PagoVentaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un pago recibido sobre una venta: el pago único de un contado, el inicial
 * de un crédito/apartado, el pago de una cuota de crédito, o un abono libre
 * de un apartado. Mirror de Cobro (módulo Credito).
 */
class PagoVenta extends Model
{
    /** @use HasFactory<PagoVentaFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'pagos_venta';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'venta_id',
        'cuota_venta_id',
        'empresa_id',
        'caja_ciclo_id',
        'caja_movimiento_id',
        'registrado_por',
        'tipo',
        'monto',
        'medio',
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
            'monto' => 'decimal:2',
            'anulado_at' => 'datetime',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function cuotaVenta(): BelongsTo
    {
        return $this->belongsTo(CuotaVenta::class);
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

    protected static function newFactory(): PagoVentaFactory
    {
        return PagoVentaFactory::new();
    }
}
