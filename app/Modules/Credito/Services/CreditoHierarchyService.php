<?php

namespace App\Modules\Credito\Services;

use App\Modules\Credito\Models\Credito;
use App\Modules\Sistemas\Services\ModuloService;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CreditoHierarchyService
{
    public function __construct(private readonly ModuloService $modulos) {}

    public function visibleQuery(Builder $query, User $actor): Builder
    {
        if ($actor->hasRole('sistemas') || $actor->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $query;
        }

        if ($actor->hasRole('administrador_agencia')) {
            return $query->where('agencia_id', $actor->agencia_id);
        }

        if ($actor->hasRole('supervisor')) {
            return $this->modulos->filtrarPorModulo(
                $query->where(function (Builder $q) use ($actor): void {
                    $q->where('registrado_por', $actor->id)
                        ->orWhereHas('registradoPor', fn (Builder $sub) => $sub->where('supervisor_id', $actor->id));
                }),
                $actor,
            );
        }

        if ($actor->hasRole('asesor')) {
            return $this->modulos->filtrarPorModulo($query->where('registrado_por', $actor->id), $actor);
        }

        return $query->whereRaw('1 = 0');
    }

    public function puedeVer(User $actor, Credito $credito): bool
    {
        return $this->visibleQuery(Credito::query(), $actor)->whereKey($credito->id)->exists();
    }

    /** Active advisers who will actually be able to see the assigned credit. */
    public function asesoresPara(Credito $credito): Collection
    {
        return User::role('asesor')
            ->where('empresa_id', $credito->empresa_id)
            ->where('agencia_id', $credito->agencia_id)
            ->where('estado', 'activo')
            ->orderBy('nombre')->orderBy('apellido')->get()
            ->filter(fn (User $user): bool => $user->can('creditos_prendarios.ver')
                && $this->modulos->tieneModulo($user, $credito->tipo_credito))->values();
    }

    public function puedeAprobar(User $actor, Credito $credito): bool
    {
        if ($actor->hasRole('administrador_agencia')) {
            return $actor->agencia_id === $credito->agencia_id;
        }

        if ($actor->hasRole('administrador_general')) {
            return $actor->empresa_id === $credito->empresa_id;
        }

        return false;
    }

    /**
     * Autoridad para dar la APROBACIÓN FINAL de un crédito. Para los tipos
     * normales es la misma que puedeAprobar() (agencia o general) — solo
     * diario agrega un paso previo: el admin de agencia únicamente puede
     * preaprobar (puedeAprobar sigue valiendo para eso), la aprobación final
     * queda exclusiva del administrador general, confirmado explícitamente
     * con el usuario. Ver CreditoService::preaprobar()/aprobar().
     */
    public function puedeAprobarFinal(User $actor, Credito $credito): bool
    {
        if ($credito->tipo_credito === 'diario') {
            return $actor->hasRole('administrador_general') && $actor->empresa_id === $credito->empresa_id;
        }

        return $this->puedeAprobar($actor, $credito);
    }

    /**
     * Every user who currently has the authority to aprobar/rechazar this
     * crédito — the "list candidates" version of puedeAprobar(), which only
     * checks one given user. Used to resolve who should receive a live
     * update when the crédito's estado changes.
     *
     * @return Collection<int, User>
     */
    public function controladoresDe(Credito $credito): Collection
    {
        return User::role('administrador_general')->where('empresa_id', $credito->empresa_id)->get()
            ->merge(User::role('administrador_agencia')->where('agencia_id', $credito->agencia_id)->get());
    }

    /**
     * Subconjunto de controladoresDe() con autoridad para dar la aprobación
     * FINAL — ver puedeAprobarFinal(). Para diario, solo administrador_general
     * de la empresa; para los demás tipos es el mismo grupo de siempre. Se usa
     * para notificar únicamente a quien puede dar el siguiente paso cuando un
     * diario queda preaprobado.
     *
     * @return Collection<int, User>
     */
    public function aprobadoresFinalesDe(Credito $credito): Collection
    {
        if ($credito->tipo_credito === 'diario') {
            return User::role('administrador_general')->where('empresa_id', $credito->empresa_id)->get();
        }

        return $this->controladoresDe($credito);
    }
}
