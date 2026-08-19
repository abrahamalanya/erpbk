<?php

namespace App\Modules\CreditoPrendario\Models;

use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CuotaCreditoPrendarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuotaCreditoPrendario extends Model
{
    /** @use HasFactory<CuotaCreditoPrendarioFactory> */
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
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(CreditoPrendario::class, 'credito_id');
    }

    protected static function newFactory(): CuotaCreditoPrendarioFactory
    {
        return CuotaCreditoPrendarioFactory::new();
    }
}
