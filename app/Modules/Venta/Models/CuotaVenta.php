<?php

namespace App\Modules\Venta\Models;

use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CuotaVentaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una cuota del cronograma de una venta a crédito (Venta::forma_venta =
 * 'credito'). monto_abonado acumula pagos parciales (adelanto), igual que
 * CuotaCredito.monto_abonado.
 */
class CuotaVenta extends Model
{
    /** @use HasFactory<CuotaVentaFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'cuotas_venta';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'venta_id',
        'empresa_id',
        'numero_cuota',
        'fecha_vencimiento',
        'monto_capital',
        'monto_interes',
        'monto_total',
        'monto_abonado',
        'estado',
    ];

    /**
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
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    protected static function newFactory(): CuotaVentaFactory
    {
        return CuotaVentaFactory::new();
    }
}
