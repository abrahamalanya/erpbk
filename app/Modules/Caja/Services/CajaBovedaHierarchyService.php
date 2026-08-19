<?php

namespace App\Modules\Caja\Services;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\Caja;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CajaBovedaHierarchyService
{
    /**
     * Roles that can see every caja/bóveda/billetaje across the whole empresa
     * (not just their own agencia). Distinct from BovedaService's funding-source
     * role lists below.
     *
     * @var list<string>
     */
    private const VISIBILIDAD_EMPRESA_COMPLETA = ['administrador_general', 'secretaria'];

    public function __construct(private readonly BovedaService $bovedaService) {}

    /**
     * Resolves (creating if needed) the bóveda that funds this user's own
     * caja/billetaje: the empresa's principal bóveda for empresa-level
     * roles, or the agencia bóveda for agencia-level roles — which includes
     * administrador_agencia, whose own caja is funded by (and returns cash
     * to) the SAME agencia bóveda they control for asesor/supervisor. They
     * approve their own billetaje request, same as administrador_general
     * already does for the principal.
     */
    public function bovedaFinanciadoraDe(User $propietario): Boveda
    {
        if ($propietario->hasAnyRole(BovedaService::ROLES_PRINCIPAL)) {
            return $this->bovedaService->principalDe($propietario->empresa_id);
        }

        if ($propietario->hasAnyRole(BovedaService::ROLES_AGENCIA)) {
            return $this->bovedaService->deAgencia($propietario->agencia_id);
        }

        throw new DomainException('El rol del usuario no participa en el módulo de Caja.');
    }

    /**
     * Whether $superior is the one who controls (approves billetaje for /
     * can force-close cajas funded by) $boveda.
     */
    public function puedeControlarBoveda(User $superior, Boveda $boveda): bool
    {
        if ($boveda->tipo === 'principal') {
            return $superior->hasRole('administrador_general') && $superior->empresa_id === $boveda->empresa_id;
        }

        return $superior->hasRole('administrador_agencia') && $superior->agencia_id === $boveda->agencia_id;
    }

    /**
     * Safety-valve special case: administrador_general can always force-close
     * (or reabrir) an administrador_agencia's OWN caja — even though it's now
     * funded by the agencia bóveda that administrador_agencia themselves
     * controls — as an escalation path if that person is unavailable.
     * asesor/supervisor cajas stay administrador_agencia's job only.
     */
    public function puedeForzarCierre(User $superior, Caja $caja): bool
    {
        if ($caja->user->hasRole('administrador_agencia') && $superior->hasRole('administrador_general')) {
            return $superior->empresa_id === $caja->empresa_id;
        }

        return $this->puedeControlarBoveda($superior, $this->bovedaFinanciadoraDe($caja->user));
    }

    /**
     * Every user who currently controls $boveda — i.e. who's authorized to
     * approve/reject a billetaje against it (mirrors puedeControlarBoveda(),
     * listing candidates instead of checking one). Used to resolve who
     * should receive a live update when a billetaje changes.
     *
     * @return Collection<int, User>
     */
    public function controladoresDe(Boveda $boveda): Collection
    {
        if ($boveda->tipo === 'principal') {
            return User::role('administrador_general')->where('empresa_id', $boveda->empresa_id)->get();
        }

        return User::role('administrador_agencia')->where('agencia_id', $boveda->agencia_id)->get();
    }

    public function cajasVisibles(Builder $query, User $actor): Builder
    {
        if ($actor->hasRole('sistemas') || $actor->hasAnyRole(self::VISIBILIDAD_EMPRESA_COMPLETA)) {
            return $query;
        }

        if ($actor->hasRole('administrador_agencia')) {
            return $query->where('agencia_id', $actor->agencia_id);
        }

        return $query->where('user_id', $actor->id);
    }

    public function bovedasVisibles(Builder $query, User $actor): Builder
    {
        if ($actor->hasRole('sistemas') || $actor->hasAnyRole(self::VISIBILIDAD_EMPRESA_COMPLETA)) {
            return $query;
        }

        if ($actor->hasRole('administrador_agencia')) {
            return $query->where('agencia_id', $actor->agencia_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function billetajesVisibles(Builder $query, User $actor): Builder
    {
        if ($actor->hasRole('sistemas') || $actor->hasAnyRole(self::VISIBILIDAD_EMPRESA_COMPLETA)) {
            return $query;
        }

        if ($actor->hasRole('administrador_agencia')) {
            return $query->where(function (Builder $q) use ($actor): void {
                $q->whereHas('boveda', fn (Builder $b) => $b->where('agencia_id', $actor->agencia_id))
                    ->orWhere('solicitado_por', $actor->id);
            });
        }

        return $query->where('solicitado_por', $actor->id);
    }
}
