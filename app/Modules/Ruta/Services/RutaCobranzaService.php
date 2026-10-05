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
    /** Valor de `clientes_ruta_orden.tipo_credito` de la ruta sin filtro por tipo. */
    private const RUTA_GENERAL = 'todos';

    /**
     * Clientes de $propietario (cliente.asesor_id) con al menos un crédito
     * activo/vencido que tiene una cuota vencida u hoy — mismo criterio que
     * ReporteCobranzaService::cobranzaDiaria(), pero agrupado por CLIENTE en
     * vez de por crédito: la ruta visita direcciones, no créditos, así que
     * un cliente con 2 créditos vencidos es una sola parada.
     *
     * Un supervisor no tiene cartera propia: su ruta son las paradas que él
     * clonó de las rutas de sus asesores (filas en clientes_ruta_orden con su
     * id), así que además de su cartera entran los clientes que ya están en su
     * ruta aunque sean de otro asesor — mientras ese asesor siga a su alcance
     * (ver clientesAjenosVisibles()).
     *
     * Solo cuentan las cuotas todavía pendientes: una cuota vencida que ya se
     * cobró (sobre todo con el pago por cuotas) no debe seguir apareciendo
     * en la ruta. Con `$tipoCredito` la ruta se limita a los créditos de ese
     * tipo — la ruta se recorre por tipo (diario, prendario, hipotecario,
     * vehicular) — y los conteos y días de atraso salen solo de ellos.
     *
     * @return Collection<int, array{cliente: Cliente, dias_atraso_max: int, creditos_vencidos: int, credito_codigos: list<string>, creditos: list<array{id: int, codigo: string, tipo_credito: string}>}>
     */
    private function clientesEnMoraDe(User $propietario, ?string $tipoCredito): Collection
    {
        $hoy = now()->startOfDay()->toDateString();

        $ajenos = $this->clientesAjenosVisibles($propietario, $tipoCredito);

        $creditos = Credito::query()
            ->whereIn('estado', ['activo', 'vencido'])
            ->when($tipoCredito !== null, fn (Builder $q) => $q->where('tipo_credito', $tipoCredito))
            ->whereHas('cliente', function (Builder $cliente) use ($propietario, $ajenos): void {
                $cliente->where('asesor_id', $propietario->id);

                if ($ajenos->isNotEmpty()) {
                    $cliente->orWhereIn('id', $ajenos);
                }
            })
            ->whereHas('cuotas', fn (Builder $q) => $q->pendientes()->whereDate('fecha_vencimiento', '<=', $hoy))
            ->with(['cliente'])
            ->get()
            ->load(['cuotas' => fn ($q) => $q->pendientes()->whereDate('fecha_vencimiento', '<=', $hoy)->orderBy('fecha_vencimiento')]);

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
                    'creditos' => $creditosDelCliente->map(fn (Credito $c): array => [
                        'id' => $c->id,
                        'codigo' => $c->codigo,
                        'tipo_credito' => $c->tipo_credito,
                    ])->values()->all(),
                ];
            })
            ->values();
    }

    /**
     * Ruta ordenada de $propietario para hoy: sus clientes en mora, en el
     * orden que el propio asesor definió (persistente entre días — ver
     * clientes_ruta_orden). Un cliente que entra en mora por primera vez se
     * agrega al final automáticamente (ordenado por días de atraso
     * descendente entre los recién agregados, para que el más urgente quede
     * primero de los nuevos).
     *
     * Con `$tipoCredito` es la ruta de ese tipo de crédito, con su propio
     * orden (un cliente con un diario y un prendario en mora está en las dos
     * rutas, y se ordena por separado en cada una); sin él, la ruta general.
     *
     * `$actor` es quien consulta la ruta (puede ser otro que el propietario:
     * un supervisor viendo la ruta de su asesor). Solo se usa para marcar
     * `en_mi_ruta` en cada parada, que es lo que le permite al frontend saber
     * si un cliente de la ruta de un asesor ya está copiado en la suya.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rutaDe(User $propietario, ?string $tipoCredito = null, ?User $actor = null): Collection
    {
        $actor ??= $propietario;

        $clientesEnMora = $this->clientesEnMoraDe($propietario, $tipoCredito);

        if ($clientesEnMora->isEmpty()) {
            return collect();
        }

        $ruta = $tipoCredito ?? self::RUTA_GENERAL;

        $ordenes = RutaClienteOrden::query()
            ->where('asesor_id', $propietario->id)
            ->where('tipo_credito', $ruta)
            ->whereIn('cliente_id', $clientesEnMora->pluck('cliente.id'))
            ->get()
            ->keyBy('cliente_id');

        $siguienteOrden = (int) RutaClienteOrden::query()->where('asesor_id', $propietario->id)->where('tipo_credito', $ruta)->max('orden');

        $nuevos = $clientesEnMora
            ->reject(fn (array $fila) => $ordenes->has($fila['cliente']->id))
            ->sortByDesc('dias_atraso_max');

        foreach ($nuevos as $fila) {
            $siguienteOrden++;

            $ordenes->put($fila['cliente']->id, RutaClienteOrden::query()->create([
                'empresa_id' => $propietario->empresa_id,
                'asesor_id' => $propietario->id,
                'cliente_id' => $fila['cliente']->id,
                'tipo_credito' => $ruta,
                'orden' => $siguienteOrden,
            ]));
        }

        // Paradas de la ruta de $actor: es la del propio propietario salvo que
        // se esté mirando la de otro (filtro de asesores del supervisor).
        $enMiRuta = $actor->id === $propietario->id
            ? $clientesEnMora->pluck('cliente.id')
            : $this->clientesEnRutaDe($actor, $tipoCredito);

        return $clientesEnMora
            ->map(fn (array $fila): array => [
                'orden' => $ordenes->get($fila['cliente']->id)->orden,
                'cliente_id' => $fila['cliente']->id,
                'nombre' => $fila['cliente']->nombre,
                'apellido' => $fila['cliente']->apellido,
                'numero_documento' => $fila['cliente']->numero_documento,
                'direccion' => $fila['cliente']->direccion,
                'referencia' => $fila['cliente']->referencia,
                'latitud' => $fila['cliente']->latitud !== null ? (float) $fila['cliente']->latitud : null,
                'longitud' => $fila['cliente']->longitud !== null ? (float) $fila['cliente']->longitud : null,
                'dias_atraso_max' => $fila['dias_atraso_max'],
                'creditos_vencidos' => $fila['creditos_vencidos'],
                'credito_codigos' => $fila['credito_codigos'],
                'creditos' => $fila['creditos'],
                'asesor_id' => $fila['cliente']->asesor_id,
                'clonado' => $fila['cliente']->asesor_id !== $propietario->id,
                'en_mi_ruta' => $enMiRuta->contains($fila['cliente']->id),
            ])
            ->sortBy('orden')
            ->values();
    }

    /**
     * Reescribe el orden de visita según $clienteIdsEnOrden (la lista
     * completa, ya reordenada, tal como llega del drag & drop). Solo puede
     * reordenar clientes de su propia ruta — los de su cartera más los que
     * clonó de la de otro asesor (filas en clientes_ruta_orden con su id) —
     * lo que evita que se cuelen ids de otro asesor. Reordena solo la ruta de
     * `$tipoCredito` (o la general si es null); las demás no se tocan.
     *
     * @param  list<int>  $clienteIdsEnOrden
     */
    public function reordenar(User $propietario, array $clienteIdsEnOrden, ?string $tipoCredito = null): void
    {
        $permitidos = Cliente::query()
            ->where('asesor_id', $propietario->id)
            ->whereIn('id', $clienteIdsEnOrden)
            ->pluck('id')
            ->merge($this->clientesEnRutaDe($propietario, $tipoCredito));

        if ($permitidos->unique()->count() !== count($clienteIdsEnOrden)) {
            throw new DomainException($propietario->hasRole('supervisor')
                ? 'Uno o más clientes indicados no pertenecen a tu ruta.'
                : 'Uno o más clientes indicados no pertenecen a tu cartera.');
        }

        $ruta = $tipoCredito ?? self::RUTA_GENERAL;

        DB::transaction(function () use ($propietario, $clienteIdsEnOrden, $ruta): void {
            foreach ($clienteIdsEnOrden as $posicion => $clienteId) {
                RutaClienteOrden::query()->updateOrCreate(
                    ['asesor_id' => $propietario->id, 'cliente_id' => $clienteId, 'tipo_credito' => $ruta],
                    ['empresa_id' => $propietario->empresa_id, 'orden' => $posicion + 1]
                );
            }
        });
    }

    /**
     * Copia $cliente a la ruta propia de $propietario, al final de la de ese
     * tipo de crédito: el supervisor arma su ruta de visita con paradas
     * copiadas de las rutas de sus asesores (una de cada asesor, o varias de
     * uno). El cliente NO sale de la cartera ni de la ruta de su asesor — lo
     * que se agrega es una fila más para $propietario.
     *
     * Solo un supervisor arma ruta propia. El cliente tiene que estar
     * realmente en mora de ese tipo: si ya no tiene cuotas vencidas
     * pendientes no hay nada que visitar. Si la parada ya estaba en su ruta no
     * se duplica ni se mueve de posición (así el botón puede reenviarse sin
     * efectos raros).
     */
    public function clonarCliente(User $propietario, Cliente $cliente, ?string $tipoCredito = null): void
    {
        $this->verificarQueArmaRutaPropia($propietario, 'clonar');

        $asesorOrigen = $cliente->asesor;

        if ($asesorOrigen === null) {
            throw new DomainException('El cliente no tiene un asesor asignado.');
        }

        if (! $this->puedeVerRutaDe($propietario, $asesorOrigen)) {
            throw new DomainException('Ese cliente no pertenece a ninguno de tus asesores.');
        }

        if (! $this->tieneCuotasVencidasPendientes($cliente, $tipoCredito)) {
            throw new DomainException('El cliente no tiene cuotas vencidas pendientes en esa ruta.');
        }

        $ruta = $tipoCredito ?? self::RUTA_GENERAL;

        if ($this->clientesEnRutaDe($propietario, $tipoCredito)->contains($cliente->id)) {
            return;
        }

        $siguienteOrden = (int) RutaClienteOrden::query()
            ->where('asesor_id', $propietario->id)
            ->where('tipo_credito', $ruta)
            ->max('orden');

        RutaClienteOrden::query()->create([
            'empresa_id' => $propietario->empresa_id,
            'asesor_id' => $propietario->id,
            'cliente_id' => $cliente->id,
            'tipo_credito' => $ruta,
            'orden' => $siguienteOrden + 1,
        ]);
    }

    /**
     * Quita $cliente de la ruta propia de $propietario. Solo aplica a las
     * paradas clonadas de otro asesor: un cliente de la propia cartera no se
     * quita de la ruta, se agrega solo cuando entra en mora.
     */
    public function quitarCliente(User $propietario, Cliente $cliente, ?string $tipoCredito = null): void
    {
        $this->verificarQueArmaRutaPropia($propietario, 'quitar');

        if ($cliente->asesor_id === $propietario->id) {
            throw new DomainException('Los clientes de tu cartera ya están en tu ruta, no se pueden quitar.');
        }

        RutaClienteOrden::query()
            ->where('asesor_id', $propietario->id)
            ->where('tipo_credito', $tipoCredito ?? self::RUTA_GENERAL)
            ->where('cliente_id', $cliente->id)
            ->delete();
    }

    /**
     * Armar ruta propia (clonar paradas ajenas) es potestad del supervisor;
     * para los demás roles la ruta es la de su cartera y no tiene sentido.
     */
    private function verificarQueArmaRutaPropia(User $actor, string $accion): void
    {
        if (! $actor->hasRole('supervisor')) {
            throw new DomainException("Solo un supervisor puede {$accion} clientes en su ruta.");
        }
    }

    /**
     * Si $cliente tiene cuotas vencidas (o que vencen hoy) todavía pendientes
     * en créditos activos/vencidos del tipo pedido — el mismo criterio que
     * arma la ruta, para que no se pueda copiar una parada que no existe.
     */
    private function tieneCuotasVencidasPendientes(Cliente $cliente, ?string $tipoCredito): bool
    {
        $hoy = now()->startOfDay()->toDateString();

        return Credito::query()
            ->where('cliente_id', $cliente->id)
            ->whereIn('estado', ['activo', 'vencido'])
            ->when($tipoCredito !== null, fn (Builder $q) => $q->where('tipo_credito', $tipoCredito))
            ->whereHas('cuotas', fn (Builder $q) => $q->pendientes()->whereDate('fecha_vencimiento', '<=', $hoy))
            ->exists();
    }

    /**
     * Ids de los clientes que ya tienen parada en la ruta de $propietario de
     * ese tipo de crédito — tanto los de su cartera (agregados solos al entrar
     * en mora) como los que clonó de otros asesores.
     *
     * @return Collection<int, int>
     */
    private function clientesEnRutaDe(User $propietario, ?string $tipoCredito): Collection
    {
        return RutaClienteOrden::query()
            ->where('asesor_id', $propietario->id)
            ->where('tipo_credito', $tipoCredito ?? self::RUTA_GENERAL)
            ->pluck('cliente_id');
    }

    /**
     * Clientes de OTROS asesores que están en la ruta de $propietario y cuyo
     * asesor sigue dentro de su alcance: las paradas que clonó de la ruta de
     * sus asesores. La fila sobrevive a los cambios de cartera, pero la parada
     * no: si el cliente se reasigna a otro asesor, o ese asesor pasa a otro
     * supervisor, deja de contar — una copia nunca viaja fuera del alcance de
     * quien la copió. Para un asesor siempre es vacío: su ruta es su cartera.
     *
     * @return Collection<int, int>
     */
    private function clientesAjenosVisibles(User $propietario, ?string $tipoCredito): Collection
    {
        $clientesAjenos = Cliente::query()
            ->whereIn('id', $this->clientesEnRutaDe($propietario, $tipoCredito))
            ->where('asesor_id', '!=', $propietario->id);

        if ($propietario->hasRole('administrador_agencia')) {
            return $clientesAjenos->where('agencia_id', $propietario->agencia_id)->pluck('id');
        }

        if ($propietario->hasRole('supervisor')) {
            return $clientesAjenos->whereHas('asesor', fn (Builder $q) => $q->where('supervisor_id', $propietario->id))->pluck('id');
        }

        // Cualquier otro rol (un asesor) solo tiene su cartera.
        return collect();
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
