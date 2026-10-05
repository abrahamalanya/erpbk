<?php

namespace App\Modules\Cliente\Services;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Models\ClienteFoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo de vida de las fotos múltiples de un cliente (casa, negocio y
 * adicionales), que viven en `cliente_fotos`.
 *
 * El contrato con el frontend es de "lista autorizada": en cada update, si
 * viene `fotos_{tipo}_conservar`, esa lista es el juego definitivo de ese tipo
 * —se borran del disco y de la tabla las que no estén, y el orden del arreglo
 * se vuelve a escribir en `orden`—, y las imágenes nuevas de `fotos_{tipo}` se
 * agregan al final. Si el campo no viene, no se toca nada de ese tipo.
 */
final class ClienteFotoService
{
    /**
     * Aplica al cliente los uploads nuevos y, si el request trae la lista de
     * fotos a conservar, reconcilia ese tipo. Se usa igual en store y update:
     * en un cliente recién creado no hay nada que reconciliar.
     */
    public function sincronizar(Cliente $cliente, Request $request): void
    {
        foreach (ClienteFoto::TIPOS as $tipo) {
            if ($request->has("fotos_{$tipo}_conservar")) {
                $this->reconciliar($cliente, $tipo, $request->input("fotos_{$tipo}_conservar"));
            }

            $this->agregar($cliente, $tipo, $request);
        }
    }

    /**
     * Borra del disco y de la tabla todas las fotos múltiples del cliente.
     * Para el destroy() del cliente, que es un borrado definitivo.
     */
    public function eliminarTodas(Cliente $cliente): void
    {
        $cliente->fotos()->each(fn (ClienteFoto $foto) => $this->eliminar($foto));
    }

    /**
     * Borra del disco y de la tabla una foto concreta.
     */
    public function eliminar(ClienteFoto $foto): void
    {
        Storage::disk('public')->delete($foto->path);

        $foto->delete();
    }

    /**
     * Deja el tipo exactamente como lo describe `$conservar` (ids, en el orden
     * en que llegan) y libera el resto.
     */
    private function reconciliar(Cliente $cliente, string $tipo, mixed $conservar): void
    {
        $ids = array_values(array_filter(
            array_map('intval', (array) $conservar),
            fn (int $id): bool => $id > 0,
        ));

        // La búsqueda va siempre acotada a cliente_id + tipo, así un id que
        // pertenece a otro cliente no existe para esta consulta y un cliente no
        // puede tocar fotos ajenas.
        $actuales = $cliente->fotos()->where('tipo', $tipo)->orderBy('orden')->get();

        foreach ($actuales as $foto) {
            if (! in_array($foto->id, $ids, true)) {
                $this->eliminar($foto);
            }
        }

        foreach ($ids as $orden => $id) {
            $actuales->firstWhere('id', $id)?->update(['orden' => $orden]);
        }
    }

    private function agregar(Cliente $cliente, string $tipo, Request $request): void
    {
        $archivos = array_values($request->file("fotos_{$tipo}", []));

        if ($archivos === []) {
            return;
        }

        // El max() se resuelve una sola vez, fuera del bucle: si se calculara
        // dentro, cada create() incrementaría el conteo y los `orden` saldrían
        // saltados (0, 2, 4...).
        $maximo = $cliente->fotos()->where('tipo', $tipo)->max('orden');
        $siguiente = $maximo === null ? 0 : $maximo + 1;

        foreach ($archivos as $indice => $archivo) {
            $cliente->fotos()->create([
                'tipo' => $tipo,
                'path' => $archivo->store("clientes/{$cliente->id}", 'public'),
                'orden' => $siguiente + $indice,
            ]);
        }
    }
}
