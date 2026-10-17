<?php

namespace App\Nucleo\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro de una operación de escritura identificada por una clave de
 * idempotencia enviada por el cliente (header `Idempotency-Key`). Guarda la
 * respuesta para reenviarla tal cual si la operación se repite — así un
 * reintento de la app offline (por ejemplo, porque se perdió la respuesta por
 * falta de red) no vuelve a ejecutar el efecto.
 *
 * @see \App\Nucleo\Http\Middleware\EnsureIdempotency
 */
class IdempotencyKey extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key_hash',
        'user_id',
        'method',
        'path',
        'response_status',
        'response_body',
    ];
}
