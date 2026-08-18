<?php

namespace App\Modules\Cliente\Services;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ClienteHierarchyService
{
    /**
     * @var list<string>
     */
    private const AGENCIA_LEVEL_ROLES = ['administrador_agencia', 'supervisor', 'asesor', 'peinadora'];

    public function resolveAgenciaId(User $actor, ?int $requestedAgenciaId): ?int
    {
        return $actor->hasAnyRole(self::AGENCIA_LEVEL_ROLES) ? $actor->agencia_id : $requestedAgenciaId;
    }

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
                $q->whereHas('asesor', fn (Builder $sub) => $sub->where('supervisor_id', $actor->id))
                    ->orWhere(function (Builder $sub) use ($actor): void {
                        $sub->whereNull('asesor_id')->where('agencia_id', $actor->agencia_id);
                    });
            });
        }

        if ($actor->hasRole('asesor')) {
            return $query->where('asesor_id', $actor->id);
        }

        if ($actor->hasRole('peinadora')) {
            return $query->where('registrado_por', $actor->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function canView(User $actor, Cliente $cliente): bool
    {
        if ($actor->hasRole('sistemas')) {
            return true;
        }

        if ($actor->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $actor->empresa_id === $cliente->empresa_id;
        }

        if ($actor->hasRole('administrador_agencia')) {
            return $actor->agencia_id === $cliente->agencia_id;
        }

        if ($actor->hasRole('supervisor')) {
            if ($cliente->asesor_id === null) {
                return $actor->agencia_id === $cliente->agencia_id;
            }

            return $cliente->asesor && $cliente->asesor->supervisor_id === $actor->id;
        }

        if ($actor->hasRole('asesor')) {
            return $cliente->asesor_id === $actor->id;
        }

        if ($actor->hasRole('peinadora')) {
            return $cliente->registrado_por === $actor->id;
        }

        return false;
    }

    /**
     * Stricter than canView(): governs update/delete.
     */
    public function canManage(User $actor, Cliente $cliente): bool
    {
        if ($actor->hasRole('peinadora')) {
            return $cliente->registrado_por === $actor->id && $cliente->asesor_id === null;
        }

        return $this->canView($actor, $cliente);
    }
}
