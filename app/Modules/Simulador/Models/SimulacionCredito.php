<?php

namespace App\Modules\Simulador\Models;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\SimulacionCreditoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimulacionCredito extends Model
{
    /** @use HasFactory<SimulacionCreditoFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'simulaciones_credito';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'tipo_credito',
        'cliente_id',
        'registrado_por',
        'monto_prestamo',
        'interes',
        'tipo_cuota',
        'numero_cuotas',
        'plazo_dias',
        'fecha_base',
        'monto_total_pagar',
        'cronograma',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_prestamo' => 'decimal:2',
            'interes' => 'decimal:2',
            'numero_cuotas' => 'integer',
            'fecha_base' => 'date',
            'monto_total_pagar' => 'decimal:2',
            'cronograma' => 'array',
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

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    protected static function newFactory(): SimulacionCreditoFactory
    {
        return SimulacionCreditoFactory::new();
    }
}
