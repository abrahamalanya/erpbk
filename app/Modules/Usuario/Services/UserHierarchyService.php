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
    private const EMPRESA_LEVEL_ROLES = ['administrador_general', 'secretaria'];

    /**
     * @return list<string>
     */
    public function assignableRoles(User $actor): array
    {
        foreach (self::ASSIGNABLE_ROLES as $actorRole => $targets) {
            if ($actor->hasRole($actorRole)) {
                return $targets;
            }
        }

        return [];
    }

    public function resolveEmpresaId(User $actor, string $targetRole, ?int $requestedEmpresaId): ?int
    {
        return $actor->hasRole('sistemas') ? $requestedEmpresaId : $actor->empresa_id;
    }

    public function resolveAgenciaId(User $actor, string $targetRole, ?int $requestedAgenciaId): ?int
    {
        if (in_array($targetRole, self::EMPRESA_LEVEL_ROLES, true)) {
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
