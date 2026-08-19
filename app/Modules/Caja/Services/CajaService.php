<?php

namespace App\Modules\Caja\Services;

use App\Modules\Caja\Events\BovedaActualizada;
use App\Modules\Caja\Events\CajaActualizada;
use App\Modules\Caja\Models\BovedaMovimiento;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CajaService
{
    public function __construct(
        private readonly CajaBovedaHierarchyService $hierarchy,
        private readonly BovedaService $bovedaService,
        private readonly BilletajeService $billetajeService,
    ) {}

    public function cajaDe(User $user): Caja
    {
        if ($user->empresa_id === null) {
            throw new DomainException('Este usuario no pertenece a una empresa y no participa en el módulo de Caja.');
        }

        return Caja::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['empresa_id' => $user->empresa_id, 'agencia_id' => $user->agencia_id]
        );
    }

    public function aperturar(User $actor): CajaCiclo
    {
        $caja = $this->cajaDe($actor);

        if ($caja->cicloAbierto()->exists()) {
            throw new DomainException('Ya tienes un ciclo de caja abierto.');
        }

        $boveda = $this->hierarchy->bovedaFinanciadoraDe($actor);
        $cicloBoveda = $boveda->cicloAbierto()->first();

        if ($cicloBoveda && $cicloBoveda->fecha->toDateString() < now()->startOfDay()->toDateString()) {
            throw new DomainException('No puedes aperturar tu caja: la bóveda que te financia sigue con un ciclo abierto de un día anterior.');
        }

        $this->bovedaService->asegurarAbierta($boveda, $actor);

        return DB::transaction(function () use ($caja): CajaCiclo {
            $ciclo = CajaCiclo::query()->create([
                'caja_id' => $caja->id,
                'empresa_id' => $caja->empresa_id,
                'fecha' => now()->toDateString(),
                'estado' => 'abierta',
                'saldo_apertura' => 0,
                'abierta_at' => now(),
            ]);

            $this->notificar($caja->fresh(['cicloAbierto']));

            return $ciclo;
        });
    }

    public function cerrar(User $actor, string $montoContado): CajaCiclo
    {
        $caja = $this->cajaDe($actor);
        $ciclo = $caja->cicloAbierto()->first();

        if (! $ciclo) {
            throw new DomainException('No tienes un ciclo de caja abierto.');
        }

        if ($ciclo->billetajes()->where('estado', 'pendiente')->exists()) {
            throw new DomainException('No puedes cerrar tu caja con billetajes pendientes de aprobación.');
        }

        return $this->cerrarCiclo($ciclo, $actor, $montoContado, forzado: false);
    }

    public function cerrarForzado(User $superior, Caja $caja, string $montoContado): CajaCiclo
    {
        $ciclo = $caja->cicloAbierto()->first();

        if (! $ciclo) {
            throw new DomainException('Esta caja no tiene un ciclo abierto.');
        }

        $this->billetajeService->rechazarPendientesDe(
            $ciclo,
            $superior,
            'Rechazado automáticamente por cierre forzado.'
        );

        return $this->cerrarCiclo($ciclo, $superior, $montoContado, forzado: true);
    }

    /**
     * Closes any ciclo still abierta past its own calendar day — run by the
     * scheduled cajas:cerrar-automatico command, never by a person. Since
     * nobody physically counted the cash, the arqueo is just the calculated
     * balance (no diferencia). The bóveda that funds this caja is guaranteed
     * to still be open (its own cierre is blocked while this caja is open),
     * so asegurarAbierta() below is a defensive no-op in practice.
     */
    public function cerrarAutomatico(CajaCiclo $ciclo): CajaCiclo
    {
        $this->billetajeService->rechazarPendientesDe(
            $ciclo,
            $ciclo->caja->user,
            'Rechazado automáticamente por cierre automático de fin de día.'
        );

        return $this->cerrarCiclo($ciclo, null, $ciclo->saldoActual(), forzado: false, automatico: true);
    }

    /**
     * Reverts this caja's most recently closed ciclo back to abierta — as
     * opposed to aperturar() (which always creates a new ciclo). Used by the
     * admin who controls the funding bóveda to let a late movement (e.g. a
     * forgotten billetaje) regularize against its original accounting date.
     * The owner keeps aperturar-ing their own caja normally day to day —
     * this is only for amending an already-closed ciclo.
     */
    public function reabrir(Caja $caja, User $actor): CajaCiclo
    {
        if ($caja->cicloAbierto()->exists()) {
            throw new DomainException('Esta caja ya tiene un ciclo abierto.');
        }

        $ultimoCerrado = $caja->ciclos()->where('estado', 'cerrada')->latest('cerrada_at')->first();

        if (! $ultimoCerrado) {
            throw new DomainException('Esta caja no tiene ningún ciclo cerrado para reabrir.');
        }

        $ultimoCerrado->update([
            'estado' => 'abierta',
            'cerrada_at' => null,
            'cerrada_por' => null,
        ]);

        $this->notificar($caja->fresh(['cicloAbierto']));

        return $ultimoCerrado->fresh();
    }

    private function cerrarCiclo(CajaCiclo $ciclo, ?User $actor, string $montoContado, bool $forzado, bool $automatico = false): CajaCiclo
    {
        return DB::transaction(function () use ($ciclo, $actor, $montoContado, $forzado, $automatico): CajaCiclo {
            $saldoCalculado = $ciclo->saldoActual();

            $ciclo->update([
                'estado' => 'cerrada',
                'saldo_calculado_cierre' => $saldoCalculado,
                'saldo_arqueo_cierre' => $montoContado,
                'diferencia' => bcsub($montoContado, $saldoCalculado, 2),
                'cerrada_por' => $actor?->id,
                'cerrada_at' => now(),
                'cierre_forzado' => $forzado,
                'cierre_automatico' => $automatico,
            ]);

            $boveda = $this->hierarchy->bovedaFinanciadoraDe($ciclo->caja->user);
            $bovedaCiclo = $this->bovedaService->asegurarAbierta($boveda, $actor ?? $ciclo->caja->user);

            // Reabrir()+cerrar() re-closes an already-handed-off ciclo — update
            // the existing hand-off movimiento instead of crediting the bóveda
            // twice for the same physical cash.
            $movimientoExistente = BovedaMovimiento::query()
                ->where('caja_ciclo_id', $ciclo->id)
                ->where('concepto', 'Entrega por cierre de caja')
                ->first();

            if ($movimientoExistente) {
                $movimientoExistente->update([
                    'boveda_ciclo_id' => $bovedaCiclo->id,
                    'monto' => $montoContado,
                    'registrado_por' => $actor?->id,
                    'fecha_boveda' => $bovedaCiclo->fecha,
                ]);
            } elseif (bccomp($montoContado, '0', 2) > 0) {
                BovedaMovimiento::query()->create([
                    'boveda_ciclo_id' => $bovedaCiclo->id,
                    'empresa_id' => $bovedaCiclo->empresa_id,
                    'tipo' => 'ingreso',
                    'monto' => $montoContado,
                    'concepto' => 'Entrega por cierre de caja',
                    'caja_ciclo_id' => $ciclo->id,
                    'registrado_por' => $actor?->id,
                    'fecha_boveda' => $bovedaCiclo->fecha,
                ]);
            }

            $this->notificar($ciclo->caja->fresh(['cicloAbierto']));

            BovedaActualizada::dispatch(
                $boveda,
                $bovedaCiclo->fresh()->saldoActual(),
                $this->hierarchy->controladoresDe($boveda),
            );

            return $ciclo->fresh();
        });
    }

    /**
     * Broadcasts this caja's current apertura/saldo state to its own owner
     * only — powers the header badge (no need to leave the module to see a
     * live saldo).
     */
    private function notificar(Caja $caja): void
    {
        CajaActualizada::dispatch($caja, $caja->cicloAbierto?->saldoActual());
    }
}
