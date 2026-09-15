<?php

namespace App\Modules\Ruta\Services;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\Credito;
use App\Modules\Ruta\Models\RutaClienteOrden;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RutaCobranzaService
{
    /**
     * Clientes de $asesor (cliente.asesor_id) con al menos un crédito
     * activo/vencido que tiene una cuota vencida u hoy — mismo criterio que
     * ReporteCobranzaService::cobranzaDiaria(), pero agrupado por CLIENTE en
     * vez de por crédito: la ruta visita direcciones, no créditos, así que
     * un cliente con 2 créditos vencidos es una sola parada.
     *
     * @return Collection<int, array{cliente: Cliente, dias_atraso_max: int, creditos_vencidos: int, credito_codigos: list<string>}>
     */
    private function clientesEnMoraDe(User $asesor): Collection
    {
        $hoy = now()->startOfDay()->toDateString();

        $creditos = Credito::query()
            ->whereIn('estado', ['activo', 'vencido'])
            ->whereHas('cliente', fn (Builder $q) => $q->where('asesor_id', $asesor->id))
            ->whereHas('cuotas', fn (Builder $q) => $q->whereDate('fecha_vencimiento', '<=', $hoy))
            ->with(['cliente'])
            ->get()
            ->load(['cuotas' => fn ($q) => $q->whereDate('fecha_vencimiento', '<=', $hoy)->orderBy('fecha_vencimiento')]);

        return $creditos
            ->groupBy('cliente_id')
            ->map(function (Collection $creditosDelCliente): array {
                $diasAtraso = $creditosDelCliente->map(
                    fn (Credito $c) => (int) $c->cuotas->first()->fecha_vencimiento->diffInDays(now()->startOfDay())
                );

                return [
                    'cliente' => $creditosDelCliente->first()->cliente,
                    'dias_atraso_max' => $diasAtraso->max(),
                    'creditos_vencidos' => $creditosDelCliente->count(),
                    'credito_codigos' => $creditosDelCliente->pluck('codigo')->all(),
                ];
            })
            ->values();
    }

    /**
     * Ruta ordenada de $asesor para hoy: sus clientes en mora, en el orden
     * que el propio asesor definió (persistente entre días — ver
     * clientes_ruta_orden). Un cliente que entra en mora por primera vez se
     * agrega al final automáticamente (ordenado por días de atraso
     * descendente entre los recién agregados, para que el más urgente quede
     * primero de los nuevos).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rutaDe(User $asesor): Collection
    {
        $clientesEnMora = $this->clientesEnMoraDe($asesor);

        if ($clientesEnMora->isEmpty()) {
            return collect();
        }

        $ordenes = RutaClienteOrden::query()
            ->where('asesor_id', $asesor->id)
            ->whereIn('cliente_id', $clientesEnMora->pluck('cliente.id'))
            ->get()
            ->keyBy('cliente_id');

        $siguienteOrden = (int) RutaClienteOrden::query()->where('asesor_id', $asesor->id)->max('orden');

        $nuevos = $clientesEnMora
            ->reject(fn (array $fila) => $ordenes->has($fila['cliente']->id))
            ->sortByDesc('dias_atraso_max');

        foreach ($nuevos as $fila) {
            $siguienteOrden++;

            $ordenes->put($fila['cliente']->id, RutaClienteOrden::query()->create([
                'empresa_id' => $asesor->empresa_id,
                'asesor_id' => $asesor->id,
                'cliente_id' => $fila['cliente']->id,
                'orden' => $siguienteOrden,
            ]));
        }

        return $clientesEnMora
            ->map(fn (array $fila): array => [
                'orden' => $ordenes->get($fila['cliente']->id)->orden,
                'cliente_id' => $fila['cliente']->id,
                'nombre' => $fila['cliente']->nombre,
                'apellido' => $fila['cliente']->apellido,
                'direccion' => $fila['cliente']->direccion,
                'referencia' => $fila['cliente']->referencia,
                'latitud' => $fila['cliente']->latitud !== null ? (float) $fila['cliente']->latitud : null,
                'longitud' => $fila['cliente']->longitud !== null ? (float) $fila['cliente']->longitud : null,
                'dias_atraso_max' => $fila['dias_atraso_max'],
                'creditos_vencidos' => $fila['creditos_vencidos'],
                'credito_codigos' => $fila['credito_codigos'],
            ])
            ->sortBy('orden')
            ->values();
    }

    /**
     * Reescribe el orden de visita según $clienteIdsEnOrden (la lista
     * completa, ya reordenada, tal como llega del drag & drop). Solo puede
     * reordenar clientes que ya son suyos (cliente.asesor_id === $asesor->id)
     * — evita que se cuelen ids de otro asesor.
     *
     * @param  list<int>  $clienteIdsEnOrden
     */
    public function reordenar(User $asesor, array $clienteIdsEnOrden): void
    {
        $propios = Cliente::query()
            ->where('asesor_id', $asesor->id)
            ->whereIn('id', $clienteIdsEnOrden)
            ->pluck('id');

        if ($propios->count() !== count($clienteIdsEnOrden)) {
            throw new DomainException('Uno o más clientes indicados no pertenecen a tu cartera.');
        }

        DB::transaction(function () use ($asesor, $clienteIdsEnOrden): void {
            foreach ($clienteIdsEnOrden as $posicion => $clienteId) {
                RutaClienteOrden::query()->updateOrCreate(
                    ['asesor_id' => $asesor->id, 'cliente_id' => $clienteId],
                    ['empresa_id' => $asesor->empresa_id, 'orden' => $posicion + 1]
                );
            }
        });
    }

    /**
     * Whether $actor can view $asesor's ruta — mismo alcance que
     * UserHierarchyService::canManage() más el caso supervisor (solo sus
     * propios asesores) y el propio asesor (solo su ruta).
     */
    public function puedeVerRutaDe(User $actor, User $asesor): bool
    {
        if ($actor->id === $asesor->id) {
            return true;
        }

        if ($actor->hasAnyRole(['sistemas', 'administrador_general', 'secretaria'])) {
            return $actor->hasRole('sistemas') || $actor->empresa_id === $asesor->empresa_id;
        }

        if ($actor->hasRole('administrador_agencia')) {
            return $actor->agencia_id === $asesor->agencia_id;
        }

        if ($actor->hasRole('supervisor')) {
            return $asesor->supervisor_id === $actor->id;
        }

        return false;
    }

    /**
     * Usuarios con rol asesor visibles para $actor, para el selector del
     * mapa de ruta — un administrador ve todos los de su alcance, un
     * supervisor solo los suyos. Un asesor no necesita este listado (ve
     * directamente su propia ruta).
     *
     * @return Collection<int, User>
     */
    public function asesoresVisibles(User $actor): Collection
    {
        $query = User::query()->whereHas('roles', fn (Builder $q) => $q->where('name', 'asesor'));

        if ($actor->hasRole('administrador_agencia')) {
            $query->where('agencia_id', $actor->agencia_id);
        } elseif ($actor->hasRole('supervisor')) {
            $query->where('supervisor_id', $actor->id);
        } elseif (! $actor->hasAnyRole(['sistemas', 'administrador_general', 'secretaria'])) {
            return collect();
        }

        return $query->orderBy('nombre')->get();
    }
}
