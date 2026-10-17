<?php

namespace App\Nucleo\Http\Middleware;

use App\Nucleo\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soporte de idempotencia para escrituras. Si la petición trae el header
 * `Idempotency-Key` (lo manda la app cuando encola una operación offline), la
 * operación se ejecuta una sola vez: la respuesta se memoriza y, si el cliente
 * la reintenta con la misma clave (p. ej. porque se perdió la respuesta por
 * falta de red), se devuelve la respuesta guardada sin volver a ejecutar nada.
 *
 * Sin el header el comportamiento es exactamente el de siempre, así que la web
 * y cualquier cliente existente no se ven afectados.
 */
class EnsureIdempotency
{
    /** Métodos con efecto; en el resto no tiene sentido memorizar. */
    private const METODOS_CON_EFECTO = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $clave = $request->header('Idempotency-Key');

        if (! is_string($clave) || $clave === '' || ! in_array($request->method(), self::METODOS_CON_EFECTO, true)) {
            return $next($request);
        }

        // La clave se acota al usuario: la misma clave en dos sesiones distintas
        // no debe compartir respuesta.
        $hash = hash('sha256', ($request->user()?->id ?? 'guest').'|'.$clave);

        $existente = IdempotencyKey::query()->where('key_hash', $hash)->first();

        if ($existente) {
            if ($existente->response_status !== null && $existente->response_body !== null) {
                return response($existente->response_body, $existente->response_status, [
                    'Content-Type' => 'application/json',
                    'Idempotency-Replayed' => 'true',
                ]);
            }

            return $this->enProgreso();
        }

        try {
            $registro = IdempotencyKey::query()->create([
                'key_hash' => $hash,
                'user_id' => $request->user()?->id,
                'method' => $request->method(),
                'path' => '/'.$request->path(),
            ]);
        } catch (QueryException) {
            // Carrera: otra petición reclamó la misma clave primero.
            return $this->enProgreso();
        }

        $response = $next($request);
        $status = $response->getStatusCode();
        $contenido = $response->getContent();

        if ($status >= 200 && $status < 300 && is_string($contenido)) {
            $registro->forceFill([
                'response_status' => $status,
                'response_body' => $contenido,
            ])->save();
        } else {
            // Validación, permisos, conflicto o una respuesta que no se puede
            // memorizar (p. ej. un stream): no se memoriza, para permitir
            // corregir y reintentar con la misma clave.
            $registro->delete();
        }

        return $response;
    }

    private function enProgreso(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'La solicitud ya se está procesando.',
            'data' => null,
            'idempotency' => 'in_progress',
        ], 409);
    }
}
