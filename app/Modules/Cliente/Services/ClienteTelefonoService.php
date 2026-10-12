<?php

namespace App\Modules\Cliente\Services;

use App\Modules\Cliente\Models\Cliente;
use Illuminate\Http\Request;

/**
 * Ciclo de vida de los teléfonos múltiples de un cliente, que viven en
 * `cliente_telefonos`.
 *
 * El contrato con el frontend es de "lista autorizada": si el request trae
 * `telefonos`, esa lista es el juego definitivo —se reemplaza por completo,
 * en el orden del arreglo—. Si el campo no viene, no se toca nada.
 *
 * Los números en blanco se ignoran: un multipart/FormData no tiene forma de
 * mandar un arreglo vacío (la clave simplemente no se envía, indistinguible
 * de "no tocar nada"), así que el frontend/app mandan un string vacío como
 * marcador de "la lista quedó vacía" cuando el usuario borra todos los
 * números — sin este filtro, ese marcador se guardaría como un número.
 */
final class ClienteTelefonoService
{
    public function sincronizar(Cliente $cliente, Request $request): void
    {
        if (! $request->has('telefonos')) {
            return;
        }

        $cliente->telefonos()->delete();

        $numeros = array_filter(
            (array) $request->input('telefonos'),
            fn (mixed $numero): bool => trim((string) $numero) !== '',
        );

        foreach (array_values($numeros) as $orden => $numero) {
            $cliente->telefonos()->create([
                'numero' => $numero,
                'orden' => $orden,
            ]);
        }
    }
}
