<?php

namespace App\Modules\Usuario\Services;

use App\Modules\Usuario\Models\User;

final class UserHierarchyService
{
    /**
     * Which roles each actor role is structurally allowed to assign to a new
     * user. This is a fixed ceiling the permission system can only narrow,
     * never widen.
     *
     * @var array<string, list<string>>
     */
    private const ASSIGNABLE_ROLES = [
        'sistemas' => ['administrador_general', 'secretaria', 'administrador_agencia', 'supervisor', 'peinadora', 'asesor'],
        'administrador_general' => ['secretaria', 'administrador_agencia', 'supervisor', 'peinadora', 'asesor'],
        'secretaria' => ['administrador_agencia', 'supervisor', 'peinadora', 'asesor'],
        'administrador_agencia' => ['supervisor', 'peinadora', 'asesor'],
    ];

    /**
     * @var list<string>
     */
    private const AGENCIA_LEVEL_ROLES = ['administrador_agencia', 'peinadora', 'supervisor', 'asesor'];

    /**
     * Union of every role each of the actor's own roles is allowed to assign.
     * An actor may wear several hats, so the ceiling is the combination of all.
     *
     * @return list<string>
     */
    public function assignableRoles(User $actor): array
    {
        $targets = [];

        foreach (self::ASSIGNABLE_ROLES as $actorRole => $roles) {
            if ($actor->hasRole($actorRole)) {
                $targets = array_merge($targets, $roles);
            }
        }

        return array_values(array_unique($targets));
    }

    public function resolveEmpresaId(User $actor, ?int $requestedEmpresaId): ?int
    {
        return $actor->hasRole('sistemas') ? $requestedEmpresaId : $actor->empresa_id;
    }

    /**
     * @param  list<string>  $roles
     */
    public function includesAgenciaLevelRole(array $roles): bool
    {
        return (bool) array_intersect($roles, self::AGENCIA_LEVEL_ROLES);
    }

    /**
     * The user gets an agencia as soon as *any* of its target roles is an
     * agencia-level role; a purely empresa-level set stays agencia-less.
     *
     * @param  list<string>  $targetRoles
     */
    public function resolveAgenciaId(User $actor, array $targetRoles, ?int $requestedAgenciaId): ?int
    {
        if (! array_intersect($targetRoles, self::AGENCIA_LEVEL_ROLES)) {
            return null;
        }

        return $actor->hasRole('administrador_agencia') ? $actor->agencia_id : $requestedAgenciaId;
    }

    /**
     * Whether $actor's hierarchy position reaches $target for view/update/delete.
     */
    public function canManage(User $actor, User $target): bool
    {
        if ($actor->hasAnyRole(['administrador_general', 'secretaria'])) {
            return $actor->empresa_id === $target->empresa_id;
        }

        if ($actor->hasRole('administrador_agencia')) {
            return $actor->agencia_id === $target->agencia_id;
        }

        return false;
    }
}
