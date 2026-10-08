<?php

namespace App\Modules\Facturacion\Models;

use App\Nucleo\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de envío a SUNAT. Solo escribe el job de envío, nunca un request
 * de usuario: es la evidencia de qué se mandó, qué respondió SUNAT y cuándo.
 */
class ComprobanteEnvio extends Model
{
    use BelongsToTenant;

    protected $table = 'comprobante_envios';

    /** @var list<string> */
    protected $fillable = [
        'comprobante_id',
        'empresa_id',
        'intento',
        'estado',
        'nombre_archivo',
        'request_xml',
        'cdr_codigo',
        'cdr_descripcion',
        'cdr_xml',
        'excepcion',
        'duracion_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intento' => 'integer',
            'duracion_ms' => 'integer',
        ];
    }

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(Comprobante::class);
    }
}
