<?php

namespace App\Modules\CreditoPrendario\Services;

use App\Modules\Cliente\Services\ClienteHierarchyService;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class BienHierarchyService
{
    public function __construct(
        private readonly ClienteHierarchyService $clienteHierarchy,
    ) {}

    /**
     * Mirrors ClienteHierarchyService::visibleQuery() — a Bien is visible to
     * whoever can see its owning Cliente, since a Bien always belongs to one.
     */
    public function visibleQuery(Builder $query, User $actor): Builder
    {
        if ($actor->hasRole('sistemas') || $actor->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $query;
        }

        if ($actor->hasRole('administrador_agencia')) {
            return $query->where('agencia_id', $actor->agencia_id);
        }

        return $query->whereHas(
            'cliente',
            fn (Builder $q) => $this->clienteHierarchy->visibleQuery($q, $actor)
        );
    }

    public function canView(User $actor, Bien $bien): bool
    {
        if ($actor->hasRole('sistemas')) {
            return true;
        }

        if ($actor->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $actor->empresa_id === $bien->empresa_id;
        }

        if ($actor->hasRole('administrador_agencia')) {
            return $actor->agencia_id === $bien->agencia_id;
        }

        return $this->clienteHierarchy->canView($actor, $bien->cliente);
    }

    /**
     * Stricter than canView() in principle (mirrors ClienteHierarchyService's
     * canView/canManage split); currently identical since no role needs a
     * tighter edit scope than its view scope for Bienes.
     */
    public function canManage(User $actor, Bien $bien): bool
    {
        return $this->canView($actor, $bien);
    }
}
