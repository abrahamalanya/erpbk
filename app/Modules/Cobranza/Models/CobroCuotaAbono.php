<?php

namespace App\Modules\Cobranza\Models;

use App\Modules\Credito\Models\CuotaCredito;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que un Cobro aplicó sobre UNA cuota de un crédito diario: la mora que
 * saldó y lo que abonó al monto de la cuota. Un mismo cobro puede tocar
 * varias cuotas y una cuota puede recibir abonos de varios cobros (pago
 * parcial / adelanto), por eso no basta con CuotaCredito::cobro_id — este
 * registro es lo que permite revertir el cobro exacto al anularlo.
 */
class CobroCuotaAbono extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'cobro_id',
        'cuota_credito_id',
        'monto_mora',
        'monto_cuota',
        'completa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_mora' => 'decimal:2',
            'monto_cuota' => 'decimal:2',
            'completa' => 'boolean',
        ];
    }

    public function cobro(): BelongsTo
    {
        return $this->belongsTo(Cobro::class);
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(CuotaCredito::class, 'cuota_credito_id');
    }
}
