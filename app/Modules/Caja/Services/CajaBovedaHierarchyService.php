<?php

namespace App\Modules\Caja\Services;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\Caja;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

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
     * roles (which includes administrador_agencia — they CONTROL their
     * agencia bóveda for others, but their OWN caja is funded one level up,
     * same as secretaria), or the agencia bóveda for agencia-level roles.
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

    public function puedeForzarCierre(User $superior, Caja $caja): bool
    {
        return $this->puedeControlarBoveda($superior, $this->bovedaFinanciadoraDe($caja->user));
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
