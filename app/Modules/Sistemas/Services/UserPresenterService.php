<?php

namespace App\Modules\Sistemas\Services;

use App\Modules\Usuario\Models\User;

/**
 * Decora un User con la información derivada que el frontend necesita para
 * renderizar permisos/módulos sin recalcularlos — usado tanto por
 * AuthController (login/me) como por ImpersonacionController (iniciar), que
 * necesitan devolver exactamente la misma forma de usuario.
 */
final class UserPresenterService
{
    public function __construct(
        private readonly ModuloService $modulos,
        private readonly PermisoTemporalService $permisosTemporales,
    ) {}

    /**
     * Named "permission_names" (not "permissions") because Spatie's
     * HasRoles trait already defines a "permissions" relationship (direct,
     * non-role permissions) on User — reusing that key would silently be
     * shadowed by the (empty, in this app) relation during serialization.
     *
     * Also attaches "modulos_efectivos": the user's *resolved* módulos
     * (their own override, or else their role's default — see
     * ModuloService::modulosEfectivos()) so the frontend can gate nav items
     * without knowing about role defaults itself. Distinct from the
     * "modulos" column already on $user, which is only the raw override.
     */
    public function decorar(User $user): User
    {
        $user->load(['roles', 'empresa']);
        $user->setAttribute('permission_names', $user->getAllPermissions()->pluck('name')->values());
        $user->setAttribute('modulos_efectivos', $this->modulos->modulosEfectivos($user));
        $user->setAttribute('permisos_temporales_clientes', $this->permisosTemporales->activasParaUsuario($user));

        return $user;
    }
}
