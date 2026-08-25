<?php

namespace App\Modules\Caja\Services;

use App\Modules\Caja\Events\BovedaActualizada;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\BovedaCiclo;
use App\Modules\Caja\Models\BovedaMovimiento;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Caja\Models\CuentaBancariaMovimiento;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function __construct(private readonly CuentaBancariaService $cuentaBancariaService) {}

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
     *
     * $medio picks WHERE the money lands: 'efectivo' (default, the bóveda's
     * cash ciclo) or 'cuenta_bancaria' (a specific cuenta bancaria of THIS
     * bóveda, given by $cuentaBancariaId) — applies to both the principal's
     * own injection and an agencia traspaso.
     *
     * For a traspaso (agencia bóveda) landing in a cuenta bancaria, the money
     * left the principal via an actual bank transfer, not physical cash — so
     * $cuentaBancariaOrigenId picks which of the principal's OWN cuentas
     * bancarias it came out of, and the egreso is debited there instead of
     * the principal's cash ciclo. Landing in efectivo still always comes out
     * of the principal's cash (unchanged, $cuentaBancariaOrigenId is ignored).
     * Doesn't apply to the principal's own inyección — that's external
     * capital materializing, with no "origen" to pick from.
     */
    public function inyectar(Boveda $boveda, User $actor, string $monto, ?string $concepto, string $medio = 'efectivo', ?int $cuentaBancariaId = null, ?int $cuentaBancariaOrigenId = null): BovedaMovimiento|CuentaBancariaMovimiento
    {
        if ($boveda->tipo === 'principal') {
            $concepto ??= 'Inyección de capital';

            if ($medio === 'cuenta_bancaria') {
                return $this->cuentaBancariaService->registrarMovimiento(
                    $this->cuentaBancariaDe($boveda, $cuentaBancariaId),
                    $actor,
                    'ingreso',
                    $monto,
                    $concepto,
                    'inyeccion',
                    (string) Str::uuid(),
                );
            }

            $ciclo = $boveda->cicloAbierto()->first();

            if (! $ciclo) {
                throw new DomainException('La bóveda principal no tiene un ciclo abierto. Apertúrala primero.');
            }

            return $this->crearMovimiento($ciclo, $actor, 'ingreso', $monto, $concepto, 'inyeccion');
        }

        return $this->trasladarDesdePrincipal($boveda, $actor, $monto, $concepto, $medio, $cuentaBancariaId, $cuentaBancariaOrigenId);
    }

    private function trasladarDesdePrincipal(Boveda $agencia, User $actor, string $monto, ?string $concepto, string $medio, ?int $cuentaBancariaId, ?int $cuentaBancariaOrigenId): BovedaMovimiento|CuentaBancariaMovimiento
    {
        $principal = $this->principalDe($agencia->empresa_id);

        // Resolved before the transaction so an invalid cuenta bancaria (or
        // insufficient saldo) fails fast, without touching the ledger at all.
        $cuentaBancariaOrigen = $medio === 'cuenta_bancaria'
            ? $this->cuentaBancariaDe($principal, $cuentaBancariaOrigenId, 'de la que saldrá el dinero')
            : null;

        if ($cuentaBancariaOrigen) {
            if (bccomp($monto, $cuentaBancariaOrigen->saldoActual(), 2) > 0) {
                throw new DomainException('La cuenta bancaria de origen no tiene saldo suficiente para este traspaso.');
            }
        } else {
            $cicloPrincipal = $principal->cicloAbierto()->first();

            if (! $cicloPrincipal) {
                throw new DomainException('La bóveda principal no tiene un ciclo abierto. Apertúrala primero.');
            }

            if (bccomp($monto, $this->calcularSaldo($cicloPrincipal), 2) > 0) {
                throw new DomainException('La bóveda principal no tiene saldo suficiente para este traspaso.');
            }
        }

        $cuentaBancariaDestino = $medio === 'cuenta_bancaria' ? $this->cuentaBancariaDe($agencia, $cuentaBancariaId) : null;
        $grupoId = (string) Str::uuid();

        return DB::transaction(function () use ($agencia, $principal, $actor, $monto, $concepto, $cuentaBancariaOrigen, $cuentaBancariaDestino, $grupoId): BovedaMovimiento|CuentaBancariaMovimiento {
            if ($cuentaBancariaOrigen) {
                $this->cuentaBancariaService->registrarMovimiento(
                    $cuentaBancariaOrigen,
                    $actor,
                    'egreso',
                    $monto,
                    $concepto ?? 'Traspaso a bóveda de agencia',
                    'traspaso',
                    $grupoId,
                );
            } else {
                $cicloPrincipal = $principal->cicloAbierto()->firstOrFail();
                $this->crearMovimiento($cicloPrincipal, $actor, 'egreso', $monto, $concepto ?? 'Traspaso a bóveda de agencia', 'traspaso', $grupoId);
            }

            if ($cuentaBancariaDestino) {
                return $this->cuentaBancariaService->registrarMovimiento(
                    $cuentaBancariaDestino,
                    $actor,
                    'ingreso',
                    $monto,
                    $concepto ?? 'Traspaso desde bóveda principal',
                    'traspaso',
                    $grupoId,
                );
            }

            $cicloAgencia = $this->asegurarAbierta($agencia, $actor);

            return $this->crearMovimiento($cicloAgencia, $actor, 'ingreso', $monto, $concepto ?? 'Traspaso desde bóveda principal', 'traspaso', $grupoId);
        });
    }

    /**
     * Resolves the cuenta bancaria a traspaso/inyección should move
     * through — must belong to $boveda and be active. $rol only changes the
     * wording of the "you must pick one" error, so it reads correctly
     * whether this is the receiving or the sending side.
     */
    private function cuentaBancariaDe(Boveda $boveda, ?int $cuentaBancariaId, string $rol = 'que recibirá el dinero'): CuentaBancaria
    {
        if ($cuentaBancariaId === null) {
            throw new DomainException("Debes seleccionar la cuenta bancaria {$rol}.");
        }

        $cuenta = CuentaBancaria::query()
            ->where('boveda_id', $boveda->id)
            ->where('activa', true)
            ->find($cuentaBancariaId);

        if (! $cuenta) {
            throw new DomainException('La cuenta bancaria seleccionada no pertenece a esta bóveda o está inactiva.');
        }

        return $cuenta;
    }

    private function crearMovimiento(BovedaCiclo $ciclo, User $actor, string $tipo, string $monto, string $concepto, ?string $origen = null, ?string $grupoId = null): BovedaMovimiento
    {
        $movimiento = BovedaMovimiento::query()->create([
            'boveda_ciclo_id' => $ciclo->id,
            'empresa_id' => $ciclo->empresa_id,
            'tipo' => $tipo,
            'monto' => $monto,
            'concepto' => $concepto,
            'origen' => $origen,
            'grupo_id' => $grupoId,
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
     * reverse dependency would be circular. The broadcast saldo is the full
     * saldo_total (efectivo + cuentas bancarias activas), null while the
     * ciclo is closed — same "hide the amount when closed" UX as before,
     * just no longer cash-only. CuentaBancariaService has zero dependencies
     * of its own, so injecting it here is safe.
     */
    private function notificar(Boveda $boveda): void
    {
        $destinatarios = $boveda->tipo === 'principal'
            ? User::role('administrador_general')->where('empresa_id', $boveda->empresa_id)->get()
            : User::role('administrador_agencia')->where('agencia_id', $boveda->agencia_id)->get();

        $saldoTotal = $boveda->cicloAbierto ? $this->cuentaBancariaService->saldoTotalBoveda($boveda)['total'] : null;

        BovedaActualizada::dispatch($boveda, $saldoTotal, $destinatarios);
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

    /**
     * Every inyección/traspaso that touched this bóveda — either as cash
     * (BovedaMovimiento, tagged with origen 'inyeccion'/'traspaso' by
     * inyectar()/trasladarDesdePrincipal() above) or as a cuenta bancaria
     * credit — merged into one normalized, date-filterable list. Returned
     * as plain arrays (not Eloquent models) since the two source tables
     * have different shapes; the frontend only needs one flat report.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function reporteInyecciones(Boveda $boveda, ?string $desde, ?string $hasta): Collection
    {
        $cicloAbiertoId = $boveda->cicloAbierto?->id;

        $cash = BovedaMovimiento::query()
            ->whereHas('bovedaCiclo', fn (Builder $q) => $q->where('boveda_id', $boveda->id))
            ->whereIn('origen', ['inyeccion', 'traspaso'])
            ->when($desde, fn (Builder $q) => $q->whereDate('fecha_boveda', '>=', $desde))
            ->when($hasta, fn (Builder $q) => $q->whereDate('fecha_boveda', '<=', $hasta))
            ->with('registradoPor')
            ->get()
            ->map(fn (BovedaMovimiento $m): array => [
                'id' => $m->id,
                'medio' => 'efectivo',
                'tipo' => $m->tipo,
                'monto' => $m->monto,
                'concepto' => $m->concepto,
                'origen' => $m->origen,
                'fecha' => $m->fecha_boveda->toDateString(),
                'registrado_por' => $m->registradoPor,
                'cuenta_bancaria' => null,
                'puede_eliminar' => $m->boveda_ciclo_id === $cicloAbiertoId,
            ]);

        $banco = CuentaBancariaMovimiento::query()
            ->whereHas('cuentaBancaria', fn (Builder $q) => $q->where('boveda_id', $boveda->id))
            ->whereIn('origen', ['inyeccion', 'traspaso'])
            ->when($desde, fn (Builder $q) => $q->whereDate('fecha', '>=', $desde))
            ->when($hasta, fn (Builder $q) => $q->whereDate('fecha', '<=', $hasta))
            ->with(['registradoPor', 'cuentaBancaria.banco'])
            ->get()
            ->map(fn (CuentaBancariaMovimiento $m): array => [
                'id' => $m->id,
                'medio' => 'cuenta_bancaria',
                'tipo' => $m->tipo,
                'monto' => $m->monto,
                'concepto' => $m->concepto,
                'origen' => $m->origen,
                'fecha' => $m->fecha->toDateString(),
                'registrado_por' => $m->registradoPor,
                'cuenta_bancaria' => $m->cuentaBancaria,
                // Never independently deletable: no ciclo concept applies to
                // a cuenta bancaria. It can still be removed as the
                // automatic other side of a cash traspaso being undone —
                // see eliminarInyeccion()/eliminarParDelGrupo() below.
                'puede_eliminar' => false,
            ]);

        return $cash->concat($banco)->sortByDesc('fecha')->values();
    }

    /**
     * Undoes an inyección/traspaso — only allowed while it still belongs to
     * $boveda's CURRENTLY open ciclo (never a closed one, and never a
     * cuenta-bancaria-only row, which has no ciclo to check against — those
     * simply never reach this method since the frontend only offers the
     * action on cash rows within the open ciclo, mirrored here as a hard
     * guard rather than trusted from the client).
     */
    public function eliminarInyeccion(Boveda $boveda, int $movimientoId): void
    {
        $ciclo = $boveda->cicloAbierto()->first();

        if (! $ciclo) {
            throw new DomainException('La bóveda no tiene un ciclo abierto.');
        }

        $movimiento = BovedaMovimiento::query()
            ->where('boveda_ciclo_id', $ciclo->id)
            ->whereIn('origen', ['inyeccion', 'traspaso'])
            ->find($movimientoId);

        if (! $movimiento) {
            throw new DomainException('Este movimiento no se puede eliminar: no pertenece al ciclo actual de esta bóveda.');
        }

        DB::transaction(function () use ($movimiento, $boveda): void {
            if ($movimiento->grupo_id) {
                $this->eliminarParDelGrupo($movimiento);
            }

            $movimiento->delete();
            $this->notificar($boveda->fresh(['cicloAbierto']));
        });
    }

    /**
     * Deletes the OTHER side of a traspaso being undone, so the ledger
     * never ends up one-sided. A cash pair must still belong to ITS OWN
     * bóveda's currently open ciclo — otherwise the whole deletion is
     * refused (rather than silently leaving the traspaso half-undone). A
     * cuenta bancaria pair has no such constraint (no ciclo concept), so
     * it's always removed together with the cash side.
     */
    private function eliminarParDelGrupo(BovedaMovimiento $movimiento): void
    {
        $parCash = BovedaMovimiento::query()
            ->where('grupo_id', $movimiento->grupo_id)
            ->where('id', '!=', $movimiento->id)
            ->first();

        if ($parCash) {
            $bovedaPar = $parCash->bovedaCiclo->boveda;
            $cicloAbiertoPar = $bovedaPar->cicloAbierto()->first();

            if (! $cicloAbiertoPar || $cicloAbiertoPar->id !== $parCash->boveda_ciclo_id) {
                throw new DomainException('No se puede eliminar: el otro lado de este traspaso ya no pertenece al ciclo actual de su bóveda.');
            }

            $parCash->delete();
            $this->notificar($bovedaPar->fresh(['cicloAbierto']));

            return;
        }

        $parBanco = CuentaBancariaMovimiento::query()->where('grupo_id', $movimiento->grupo_id)->first();

        if ($parBanco) {
            $this->cuentaBancariaService->eliminarMovimientoDeGrupo($parBanco);
        }
    }
}
