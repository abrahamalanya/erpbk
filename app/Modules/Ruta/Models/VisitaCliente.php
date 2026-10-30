<?php

namespace App\Modules\Ruta\Models;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado de permanencia del asesor cerca del lugar de cobranza de un
 * cliente, para un día — ver VisitaClienteService y la migración
 * `create_visitas_cliente_table` para las reglas completas.
 */
class VisitaCliente extends Model
{
    protected $table = 'visitas_cliente';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cliente_id',
        'asesor_id',
        'dispositivo_id',
        'empresa_id',
        'agencia_id',
        'fecha',
        'inicio_en',
        'ultima_lectura_en',
        'confirmada_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'inicio_en' => 'datetime',
            'ultima_lectura_en' => 'datetime',
            'confirmada_en' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asesor_id');
    }
}
