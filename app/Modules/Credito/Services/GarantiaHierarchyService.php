<?php

namespace App\Modules\Credito\Services;

use App\Modules\Cliente\Services\ClienteHierarchyService;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Visibility rules shared by every garantía model (Bien, Vehiculo, …): a
 * garantía is visible to whoever can see its owning Cliente. Mirrors
 * ClienteHierarchyService.
 */
final class GarantiaHierarchyService
{
    public function __construct(
        private readonly ClienteHierarchyService $clienteHierarchy,
    ) {}

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
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

    public function canView(User $actor, Model $garantia): bool
    {
        if ($actor->hasRole('sistemas')) {
            return true;
        }

        if ($actor->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $actor->empresa_id === $garantia->empresa_id;
        }

        if ($actor->hasRole('administrador_agencia')) {
            return $actor->agencia_id === $garantia->agencia_id;
        }

        return $this->clienteHierarchy->canView($actor, $garantia->cliente);
    }

    /**
     * Stricter than canView() in principle; currently identical since no role
     * needs a tighter edit scope than its view scope for garantías.
     */
    public function canManage(User $actor, Model $garantia): bool
    {
        return $this->canView($actor, $garantia);
    }
}
