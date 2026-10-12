<?php

namespace App\Modules\Cliente\Models;

use Database\Factories\ClienteTelefonoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un número de teléfono del juego de números múltiples de un cliente
 * (distinto del WhatsApp, que es único y vive en `clientes.whatsapp`). Se
 * modela como una fila por número —igual que ClienteFoto— para poder borrar
 * uno suelto y conservar el orden.
 */
class ClienteTelefono extends Model
{
    /** @use HasFactory<ClienteTelefonoFactory> */
    use HasFactory;

    /** Tope de números por cliente. */
    public const MAX_POR_CLIENTE = 5;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'cliente_id',
        'numero',
        'orden',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    protected static function newFactory(): ClienteTelefonoFactory
    {
        return ClienteTelefonoFactory::new();
    }
}
