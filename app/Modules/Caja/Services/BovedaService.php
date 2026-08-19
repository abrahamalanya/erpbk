<?php

namespace App\Modules\Caja\Services;

use App\Modules\Caja\Events\BovedaActualizada;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\BovedaCiclo;
use App\Modules\Caja\Models\BovedaMovimiento;
use App\Modules\Caja\Models\Caja;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BovedaService
{
    /**
     * Roles whose OWN caja is funded by (and force-closed by) the empresa's
     * principal bóveda.
     *
     * @var list<string>
     */
    public const ROLES_PRINCIPAL = ['administrador_general', 'secretaria'];

    /**
     * Roles whose OWN caja is funded by (and force-closed by) their agencia's
     * bóveda. administrador_agencia is here (not ROLES_PRINCIPAL) because
     * their own caja is funded by the SAME agencia bóveda they control for
     * asesor/supervisor — they approve their own billetaje request, same as
     * administrador_general already does for the principal.
     *
     * @var list<string>
     */
    public const ROLES_AGENCIA = ['administrador_agencia', 'supervisor', 'asesor'];

    public function principalDe(int $empresaId): Boveda
    {
        return Boveda::query()->firstOrCreate(
            ['empresa_id' => $empresaId, 'tipo' => 'principal'],
            ['agencia_id' => null]
        );
    }

    public function deAgencia(int $agenciaId): Boveda
    {
        $agencia = Agencia::query()->findOrFail($agenciaId);

        return Boveda::query()->firstOrCreate(
            ['agencia_id' => $agenciaId],
            ['empresa_id' => $agencia->empresa_id, 'tipo' => 'agencia']
        );
    }

    /**
     * Reopens the bóveda (and, for an agencia bóveda, cascades up to ensure
     * the empresa's principal bóveda is also open) if it's currently closed.
     */
    public function asegurarAbierta(Boveda $boveda, User $actor): BovedaCiclo
    {
        $abierto = $boveda->cicloAbierto()->first();

        if ($abierto) {
            return $abierto;
        }

        if ($boveda->tipo === 'agencia') {
            $this->asegurarAbierta($this->principalDe($boveda->empresa_id), $actor);
        }

        return $this->crearCiclo($boveda, $actor, $this->saldoInicial($boveda));
    }

    /**
     * Manual apertura of the principal bóveda, driven by administrador_general
     * — as opposed to asegurarAbierta()'s automatic cascade. The very first
     * apertura in the empresa's lifetime requires an explicit saldo_inicial
     * (there's no prior ciclo to carry a balance forward from, and this is
     * the only way real capital ever enters the system). Every apertura
     * after that carries the last closed ciclo's balance forward, same as
     * asegurarAbierta() already does — saldo_inicial is ignored then.
     */
    public function aperturar(Boveda $boveda, User $actor, ?string $saldoInicial): BovedaCiclo
    {
        if ($boveda->tipo !== 'principal') {
            throw new DomainException('Solo la bóveda principal se apertura manualmente.');
        }

        if ($boveda->cicloAbierto()->exists()) {
            throw new DomainException('La bóveda ya tiene un ciclo abierto.');
        }

        $esPrimeraVez = $boveda->ciclos()->doesntExist();

        if ($esPrimeraVez && $saldoInicial === null) {
            throw new DomainException('Debes ingresar el saldo inicial de la bóveda.');
        }

        $saldoApertura = $esPrimeraVez ? $saldoInicial : $this->saldoInicial($boveda);

        return $this->crearCiclo($boveda, $actor, $saldoApertura);
    }

    /**
     * Adds capital to a bóveda, driven by administrador_general. For the
     * principal bóveda, this is an external cash injection (e.g. the owner
     * topping up) with no egreso counterpart anywhere. For an agencia
     * bóveda, it's a traspaso FROM the principal — the only way an agencia
     * bóveda ever receives fresh capital, since billetaje only ever moves
     * money out of it (to fund asesor/supervisor cajas) or back into it
     * (when those cajas close with leftover cash).
     */
    public function inyectar(Boveda $boveda, User $actor, string $monto, ?string $concepto): BovedaMovimiento
    {
        if ($boveda->tipo === 'principal') {
            $ciclo = $boveda->cicloAbierto()->first();

            if (! $ciclo) {
                throw new DomainException('La bóveda principal no tiene un ciclo abierto. Apertúrala primero.');
            }

            return $this->crearMovimiento($ciclo, $actor, 'ingreso', $monto, $concepto ?? 'Inyección de capital');
        }

        return $this->trasladarDesdePrincipal($boveda, $actor, $monto, $concepto);
    }

    private function trasladarDesdePrincipal(Boveda $agencia, User $actor, string $monto, ?string $concepto): BovedaMovimiento
    {
        $principal = $this->principalDe($agencia->empresa_id);
        $cicloPrincipal = $principal->cicloAbierto()->first();

        if (! $cicloPrincipal) {
            throw new DomainException('La bóveda principal no tiene un ciclo abierto. Apertúrala primero.');
        }

        if (bccomp($monto, $this->calcularSaldo($cicloPrincipal), 2) > 0) {
            throw new DomainException('La bóveda principal no tiene saldo suficiente para este traspaso.');
        }

        return DB::transaction(function () use ($agencia, $cicloPrincipal, $actor, $monto, $concepto): BovedaMovimiento {
            $cicloAgencia = $this->asegurarAbierta($agencia, $actor);

            $this->crearMovimiento($cicloPrincipal, $actor, 'egreso', $monto, $concepto ?? 'Traspaso a bóveda de agencia');

            return $this->crearMovimiento($cicloAgencia, $actor, 'ingreso', $monto, $concepto ?? 'Traspaso desde bóveda principal');
        });
    }

    private function crearMovimiento(BovedaCiclo $ciclo, User $actor, string $tipo, string $monto, string $concepto): BovedaMovimiento
    {
        $movimiento = BovedaMovimiento::query()->create([
            'boveda_ciclo_id' => $ciclo->id,
            'empresa_id' => $ciclo->empresa_id,
            'tipo' => $tipo,
            'monto' => $monto,
            'concepto' => $concepto,
            'registrado_por' => $actor->id,
            'fecha_boveda' => $ciclo->fecha,
        ]);

        $this->notificar($ciclo->boveda);

        return $movimiento;
    }

    /**
     * Broadcasts this bóveda's current apertura/saldo state to every admin
     * who controls it — powers a live header badge, same shape as
     * CajaService's own notificar() but fanned out to a Collection instead
     * of a single owner (a bóveda can have more than one controlling admin).
     * Duplicates CajaBovedaHierarchyService::controladoresDe() instead of
     * injecting it — that service already depends on BovedaService, so the
     * reverse dependency would be circular.
     */
    private function notificar(Boveda $boveda): void
    {
        $destinatarios = $boveda->tipo === 'principal'
            ? User::role('administrador_general')->where('empresa_id', $boveda->empresa_id)->get()
            : User::role('administrador_agencia')->where('agencia_id', $boveda->agencia_id)->get();

        BovedaActualizada::dispatch($boveda, $boveda->cicloAbierto?->saldoActual(), $destinatarios);
    }

    private function crearCiclo(Boveda $boveda, User $actor, string $saldoApertura): BovedaCiclo
    {
        return DB::transaction(function () use ($boveda, $actor, $saldoApertura): BovedaCiclo {
            $ciclo = BovedaCiclo::query()->create([
                'boveda_id' => $boveda->id,
                'empresa_id' => $boveda->empresa_id,
                'fecha' => now()->toDateString(),
                'estado' => 'abierta',
                'saldo_apertura' => $saldoApertura,
                'abierta_por' => $actor->id,
                'abierta_at' => now(),
            ]);

            $this->notificar($boveda->fresh(['cicloAbierto']));

            return $ciclo;
        });
    }

    public function cerrar(Boveda $boveda, User $actor, string $montoContado): BovedaCiclo
    {
        $ciclo = $boveda->cicloAbierto()->first();

        if (! $ciclo) {
            throw new DomainException('La bóveda no tiene un ciclo abierto.');
        }

        if ($this->tieneCajasAbiertasDebajo($boveda)) {
            throw new DomainException('No se puede cerrar la bóveda: hay cajas abiertas que dependen de ella.');
        }

        if ($boveda->tipo === 'principal' && $this->tieneBovedasAgenciaAbiertas($boveda)) {
            throw new DomainException('No se puede cerrar la bóveda principal: hay bóvedas de agencia abiertas que dependen de ella.');
        }

        return DB::transaction(function () use ($ciclo, $actor, $montoContado): BovedaCiclo {
            $saldoCalculado = $this->calcularSaldo($ciclo);

            $ciclo->update([
                'estado' => 'cerrada',
                'saldo_calculado_cierre' => $saldoCalculado,
                'saldo_arqueo_cierre' => $montoContado,
                'diferencia' => bcsub($montoContado, $saldoCalculado, 2),
                'cerrada_por' => $actor->id,
                'cerrada_at' => now(),
            ]);

            $this->notificar($ciclo->boveda->fresh(['cicloAbierto']));

            return $ciclo->fresh();
        });
    }

    /**
     * Reverts the bóveda's most recently closed ciclo back to abierta — as
     * opposed to aperturar()/asegurarAbierta(), this never creates a new
     * row. Used to regularize a late movement that must keep its original
     * accounting date (fecha_boveda), not today's.
     */
    public function reabrir(Boveda $boveda, User $actor): BovedaCiclo
    {
        if ($boveda->cicloAbierto()->exists()) {
            throw new DomainException('La bóveda ya tiene un ciclo abierto.');
        }

        $ultimoCerrado = $boveda->ciclos()
            ->where('estado', 'cerrada')
            ->latest('cerrada_at')
            ->first();

        if (! $ultimoCerrado) {
            throw new DomainException('La bóveda no tiene ningún ciclo cerrado para reabrir.');
        }

        $ultimoCerrado->update([
            'estado' => 'abierta',
            'cerrada_at' => null,
            'cerrada_por' => null,
        ]);

        $this->notificar($boveda->fresh(['cicloAbierto']));

        return $ultimoCerrado->fresh();
    }

    private function tieneBovedasAgenciaAbiertas(Boveda $boveda): bool
    {
        return Boveda::query()
            ->where('empresa_id', $boveda->empresa_id)
            ->where('tipo', 'agencia')
            ->whereHas('cicloAbierto')
            ->exists();
    }

    private function saldoInicial(Boveda $boveda): string
    {
        $ultimoCerrado = $boveda->ciclos()
            ->where('estado', 'cerrada')
            ->latest('cerrada_at')
            ->first();

        return $ultimoCerrado?->saldo_calculado_cierre ?? '0';
    }

    private function tieneCajasAbiertasDebajo(Boveda $boveda): bool
    {
        [$roles, $columna, $valor] = $boveda->tipo === 'principal'
            ? [self::ROLES_PRINCIPAL, 'empresa_id', $boveda->empresa_id]
            : [self::ROLES_AGENCIA, 'agencia_id', $boveda->agencia_id];

        return Caja::query()
            ->whereHas('user', fn (Builder $q) => $q->role($roles)->where($columna, $valor))
            ->whereHas('cicloAbierto')
            ->exists();
    }

    public function calcularSaldo(BovedaCiclo $ciclo): string
    {
        $ingresos = (string) $ciclo->movimientos()->where('tipo', 'ingreso')->sum('monto');
        $egresos = (string) $ciclo->movimientos()->where('tipo', 'egreso')->sum('monto');

        return bcadd($ciclo->saldo_apertura, bcsub($ingresos, $egresos, 2), 2);
    }
}
