<?php

namespace App\Modules\Caja\Models;

use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\ConciliacionBancariaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConciliacionBancaria extends Model
{
    /** @use HasFactory<ConciliacionBancariaFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Eloquent's default table-name guess only pluralizes the first word
     * ("conciliacion_bancarias"), not the real table ("conciliaciones_bancarias")
     * — same class of Spanish-pluralization mismatch as CuentaBancaria above.
     */
    protected $table = 'conciliaciones_bancarias';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'cuenta_bancaria_id',
        'empresa_id',
        'saldo_sistema',
        'saldo_banco',
        'diferencia',
        'observacion',
        'conciliado_por',
        'fecha',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'saldo_sistema' => 'decimal:2',
            'saldo_banco' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'fecha' => 'date',
        ];
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class);
    }

    public function conciliadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conciliado_por');
    }

    protected static function newFactory(): ConciliacionBancariaFactory
    {
        return ConciliacionBancariaFactory::new();
    }
}
