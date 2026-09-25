<?php

namespace App\Modules\Sistemas\Services;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Sistemas\Models\PermisoTemporal;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class PermisoTemporalService
{
    public const PERMISO_EDITAR_CLIENTE = 'clientes.editar';

    public const DURACION_MINUTOS = 60;

    public function puedeEditarCliente(User $actor, Cliente $cliente): bool
    {
        if ($actor->estado !== 'activo' || ! $actor->hasRole('asesor') || $cliente->asesor_id !== $actor->id) {
            return false;
        }

        return $this->queryVigentes()
            ->where('usuario_id', $actor->id)
            ->where('cliente_id', $cliente->id)
            ->where('permiso', self::PERMISO_EDITAR_CLIENTE)
            ->exists();
    }

    /**
     * Concesiones activas que el usuario puede usar para el cliente que tiene
     * asignado actualmente. Se incluye en login/auth-me para que el frontend
     * no dependa de un permiso de rol que expira.
     *
     * @return list<array{id: int, cliente_id: int, expira_at: string}>
     */
    public function activasParaUsuario(User $actor): array
    {
        return $this->queryVigentes()
            ->where('usuario_id', $actor->id)
            ->where('permiso', self::PERMISO_EDITAR_CLIENTE)
            ->whereHas('cliente', fn (Builder $query) => $query->where('asesor_id', $actor->id))
            ->get(['id', 'cliente_id', 'expira_at'])
            ->map(fn (PermisoTemporal $permiso): array => [
                'id' => $permiso->id,
                'cliente_id' => (int) $permiso->cliente_id,
                'expira_at' => $permiso->expira_at?->toIso8601String() ?? '',
            ])
            ->values()
            ->all();
    }

    public function conceder(User $actor, Cliente $cliente, string $motivo): PermisoTemporal
    {
        $this->asegurarActor($actor, $cliente);
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new DomainException('Indica el motivo de la concesión.');
        }

        return DB::transaction(function () use ($actor, $cliente, $motivo): PermisoTemporal {
            $clienteBloqueado = Cliente::query()
                ->whereKey($cliente->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($clienteBloqueado->asesor_id === null) {
                throw new DomainException('El cliente debe tener un asesor asignado para conceder este permiso.');
            }

            $asesor = User::query()->with('roles')->find($clienteBloqueado->asesor_id);

            if (! $asesor || $asesor->estado !== 'activo' || ! $asesor->hasRole('asesor')) {
                throw new DomainException('El asesor asignado al cliente no está activo o no tiene el rol asesor.');
            }

            if ($asesor->empresa_id !== $clienteBloqueado->empresa_id || $asesor->agencia_id !== $clienteBloqueado->agencia_id) {
                throw new DomainException('El asesor asignado al cliente no pertenece a su empresa o agencia.');
            }

            $yaExiste = $this->queryVigentes()
                ->where('cliente_id', $clienteBloqueado->id)
                ->where('permiso', self::PERMISO_EDITAR_CLIENTE)
                ->exists();

            if ($yaExiste) {
                throw new DomainException('Este cliente ya tiene un permiso de edición vigente.');
            }

            $concedidoAt = now();
            $expiraAt = $concedidoAt->copy()->addMinutes(self::DURACION_MINUTOS);

            return PermisoTemporal::query()->create([
                'empresa_id' => $clienteBloqueado->empresa_id,
                'usuario_id' => $asesor->id,
                'cliente_id' => $clienteBloqueado->id,
                'permiso' => self::PERMISO_EDITAR_CLIENTE,
                'motivo' => trim($motivo),
                'concedido_por' => $actor->id,
                'concedido_at' => $concedidoAt,
                'expira_at' => $expiraAt,
            ]);
        });
    }

    public function revocar(User $actor, PermisoTemporal $permiso, string $motivo): PermisoTemporal
    {
        $this->asegurarActor($actor, $permiso->cliente);
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new DomainException('Indica el motivo de la revocación.');
        }

        return DB::transaction(function () use ($actor, $permiso, $motivo): PermisoTemporal {
            $permisoBloqueado = PermisoTemporal::query()
                ->whereKey($permiso->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($permisoBloqueado->revocado_at !== null) {
                throw new DomainException('Este permiso temporal ya fue revocado.');
            }

            $permisoBloqueado->update([
                'revocado_at' => now(),
                'revocado_por' => $actor->id,
                'motivo_revocacion' => trim($motivo),
            ]);

            return $permisoBloqueado->fresh();
        });
    }

    /**
     * @return LengthAwarePaginator<int, PermisoTemporal>
     */
    public function listar(User $actor, string $estado = 'todos', int $page = 1, int $perPage = 15): LengthAwarePaginator
    {
        $this->asegurarActor($actor, null);

        return $this->queryBase()
            ->with([
                'cliente:id,empresa_id,agencia_id,asesor_id,nombre,apellido,numero_documento',
                'usuario:id,empresa_id,agencia_id,nombre,apellido',
                'concedidoPorUsuario' => fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->select(['id', 'empresa_id', 'agencia_id', 'nombre', 'apellido']),
                'revocadoPorUsuario' => fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->select(['id', 'empresa_id', 'agencia_id', 'nombre', 'apellido']),
            ])
            ->when($estado === 'vigente', fn (Builder $query) => $query->whereNull('revocado_at')->where('expira_at', '>', now()))
            ->when($estado === 'expirado', fn (Builder $query) => $query->whereNull('revocado_at')->where('expira_at', '<=', now()))
            ->when($estado === 'revocado', fn (Builder $query) => $query->whereNotNull('revocado_at'))
            ->latest('concedido_at')
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    private function asegurarActor(User $actor, ?Cliente $cliente = null): void
    {
        if ($actor->hasRole('sistemas')) {
            return;
        }

        if (! $actor->hasRole('administrador_general') || ! $actor->can('gestion.permisos_temporales')) {
            throw new DomainException('No tienes permiso para gestionar permisos temporales.');
        }

        if ($cliente && $actor->empresa_id !== $cliente->empresa_id) {
            throw new DomainException('No puedes gestionar clientes de otra empresa.');
        }
    }

    private function queryBase(): Builder
    {
        return PermisoTemporal::query()->where('permiso', self::PERMISO_EDITAR_CLIENTE);
    }

    private function queryVigentes(): Builder
    {
        return $this->queryBase()
            ->whereNull('revocado_at')
            ->where('expira_at', '>', now());
    }
}
