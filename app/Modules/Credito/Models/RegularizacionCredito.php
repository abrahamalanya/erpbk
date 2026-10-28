<?php

namespace App\Modules\Credito\Models;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de "regularizaciones" de un crédito diario — ver
 * CreditoService::regularizarCuotas(): condona por completo un número de
 * cuotas pendientes (capital+interés+mora) sin generar ningún Cobro ni
 * movimiento de caja. Esta fila es el único registro contable/auditable de
 * la operación.
 */
class RegularizacionCredito extends Model
{
    use BelongsToTenant;

    protected $table = 'regularizaciones_credito';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'credito_id',
        'actor_id',
        'numero_cuotas',
        'monto_condonado',
        'motivo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'numero_cuotas' => 'integer',
            'monto_condonado' => 'decimal:2',
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

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
