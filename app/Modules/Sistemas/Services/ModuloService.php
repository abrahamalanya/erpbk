<?php

namespace App\Modules\Sistemas\Services;

use App\Modules\Sistemas\Models\Modulo;
use App\Modules\Sistemas\Models\Role;
use App\Modules\Usuario\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Módulos are the app's toggleable business areas (créditos prendarios,
 * créditos diarios, cajas, solicitudes, ...), catalogued in the `modulos`
 * table. Each Role has a default set (`role_modulo`); a user can override
 * that default with their own explicit set (User::modulos, nullable JSON —
 * null means "no override, use my role's default").
 *
 * 'sistemas' always gets every módulo, bypassing role/user config entirely —
 * mirrors how it bypasses every other authorization check in this app (see
 * UserPolicy::before(), CreditoHierarchyService::visibleQuery()).
 *
 * Named access profiles replace the module selection without granting any
 * operation permissions. Users without a profile preserve role defaults and
 * the legacy asesor/supervisor overrides (ROLES_RESTRINGIBLES).
 *
 * Créditos prendarios/diarios/hipotecarios/vehiculares additionally gate
 * *registering* a new crédito of that tipo (autorizarCreacion()) and
 * *listing/viewing* créditos of a tipo the user no longer has
 * (filtrarPorModulo()). For profiled users, PerfilCreditoScope also protects
 * reads, route bindings and the shared post-registration lifecycle.
 */
final class ModuloService
{
    /**
     * Roles whose módulos can be overridden per user.
     *
     * @var list<string>
     */
    public const ROLES_RESTRINGIBLES = ['asesor', 'supervisor'];

    /**
     * @return Collection<int, Modulo>
     */
    public function catalogo(): Collection
    {
        return Modulo::query()->orderBy('grupo')->orderBy('nombre')->get();
    }

    /**
     * The módulos $user actually has access to right now: every módulo for
     * 'sistemas', their active profile, or the legacy override/role defaults.
     *
     * @return list<string>
     */
    public function modulosEfectivos(User $user): array
    {
        if ($user->hasRole('sistemas')) {
            return Modulo::query()->pluck('key')->all();
        }

        if ($user->perfil_acceso_id !== null) {
            $perfil = $user->perfilAcceso;

            return $perfil?->activo ? $perfil->modulos()->pluck('key')->all() : [];
        }

        if ($user->modulos !== null) {
            return $user->modulos;
        }

        $roleIds = $user->roles->pluck('id');

        if ($roleIds->isEmpty()) {
            return [];
        }

        return Modulo::query()
            ->whereHas('roles', fn (Builder $q) => $q->whereIn('roles.id', $roleIds))
            ->pluck('key')
            ->unique()
            ->values()
            ->all();
    }

    public function tieneModulo(User $user, string $modulo): bool
    {
        return in_array($modulo, $this->modulosEfectivos($user), true);
    }

    /** Adds the profile boundary without changing legacy authorization. */
    public function perfilPermite(User $user, string ...$modulos): bool
    {
        return $user->perfil_acceso_id === null || $user->hasRole('sistemas')
            || array_intersect($modulos, $this->modulosEfectivos($user)) !== [];
    }

    /**
     * The user's own override, or null if they have none (inheriting their
     * role's default).
     *
     * @return list<string>|null
     */
    public function asignadosA(User $user): ?array
    {
        return $user->modulos;
    }

    /**
     * @param  list<string>|null  $modulos  Null clears the override (back to
     *                                      inheriting the role default); an
     *                                      array (even empty) sets an
     *                                      explicit one for this user.
     */
    public function asignar(User $user, ?array $modulos): void
    {
        $user->update(['modulos' => $modulos]);
    }

    /**
     * @return list<string>
     */
    public function modulosDelRol(Role $role): array
    {
        return $role->modulos()->pluck('key')->all();
    }

    /**
     * @param  list<string>  $modulos
     */
    public function asignarARol(Role $role, array $modulos): void
    {
        $ids = Modulo::query()->whereIn('key', $modulos)->pluck('id');

        $role->modulos()->sync($ids);
    }

    /**
     * @throws AuthorizationException
     */
    public function autorizarCreacion(User $user, string $modulo): void
    {
        if (! $this->tieneModulo($user, $modulo)) {
            throw new AuthorizationException("No tienes acceso al módulo de créditos {$modulo}.");
        }
    }

    /**
     * Narrows $query to only the tipo_credito values $actor currently has
     * access to. $column is the tipo_credito column's name, qualified by the
     * caller if the query joins other tables.
     */
    public function filtrarPorModulo(Builder $query, User $actor, string $column = 'tipo_credito'): Builder
    {
        return $query->whereIn($column, $this->modulosEfectivos($actor));
    }
}
