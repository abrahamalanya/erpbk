<?php

namespace App\Modules\Credito\Services;

use App\Modules\Credito\Models\Credito;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CreditoHierarchyService
{
    public function visibleQuery(Builder $query, User $actor): Builder
    {
        if ($actor->hasRole('sistemas') || $actor->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $query;
        }

        if ($actor->hasRole('administrador_agencia')) {
            return $query->where('agencia_id', $actor->agencia_id);
        }

        if ($actor->hasRole('supervisor')) {
            return $query->where(function (Builder $q) use ($actor): void {
                $q->where('registrado_por', $actor->id)
                    ->orWhereHas('registradoPor', fn (Builder $sub) => $sub->where('supervisor_id', $actor->id));
            });
        }

        if ($actor->hasRole('asesor')) {
            return $query->where('registrado_por', $actor->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function puedeVer(User $actor, Credito $credito): bool
    {
        return $this->visibleQuery(Credito::query(), $actor)->whereKey($credito->id)->exists();
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
}
