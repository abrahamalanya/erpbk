<?php

namespace App\Modules\CreditoPrendario\Services;

use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class CreditoPrendarioHierarchyService
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

    public function puedeVer(User $actor, CreditoPrendario $credito): bool
    {
        return $this->visibleQuery(CreditoPrendario::query(), $actor)->whereKey($credito->id)->exists();
    }

    public function puedeAprobar(User $actor, CreditoPrendario $credito): bool
    {
        if ($actor->hasRole('administrador_agencia')) {
            return $actor->agencia_id === $credito->agencia_id;
        }

        if ($actor->hasRole('administrador_general')) {
            return $actor->empresa_id === $credito->empresa_id;
        }

        return false;
    }
}
