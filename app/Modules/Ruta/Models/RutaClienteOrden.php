<?php

namespace App\Modules\Ruta\Models;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Posición de un cliente en la ruta de cobranza de un asesor — 1 fila por
 * (asesor, cliente, tipo de crédito), persistente entre días (ver
 * RutaCobranzaService). `tipo_credito` 'todos' es la ruta sin filtro.
 */
class RutaClienteOrden extends Model
{
    use BelongsToTenant;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'clientes_ruta_orden';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'asesor_id',
        'cliente_id',
        'tipo_credito',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
        ];
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asesor_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
