<?php

namespace App\Modules\Caja\Services;

use App\Modules\Caja\Events\BilletajeActualizado;
use App\Modules\Caja\Events\BovedaActualizada;
use App\Modules\Caja\Events\CajaActualizada;
use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Models\BovedaMovimiento;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Notifications\BilletajeAprobadoNotification;
use App\Modules\Caja\Notifications\BilletajeSolicitadoNotification;
use App\Modules\Sistemas\Services\NotificacionService;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class BilletajeService
{
    public function __construct(
        private readonly CajaBovedaHierarchyService $hierarchy,
        private readonly BovedaService $bovedaService,
        private readonly NotificacionService $notificaciones,
        private readonly CuentaBancariaService $cuentaBancariaService,
    ) {}

    public function solicitar(User $actor, string $monto, string $motivo, string $medioRecepcion, ?string $datosRecepcion): Billetaje
    {
        $caja = Caja::query()->where('user_id', $actor->id)->first();
        $ciclo = $caja?->cicloAbierto()->first();

        if (! $ciclo) {
            throw new DomainException('Debes aperturar tu caja antes de solicitar billetaje.');
        }

        $boveda = $this->hierarchy->bovedaFinanciadoraDe($actor);

        $billetaje = Billetaje::query()->create([
            'caja_ciclo_id' => $ciclo->id,
            'boveda_id' => $boveda->id,
            'empresa_id' => $ciclo->empresa_id,
            'monto' => $monto,
            'estado' => 'pendiente',
            'motivo' => $motivo,
            'medio_recepcion' => $medioRecepcion,
            'datos_recepcion' => $datosRecepcion,
            'solicitado_por' => $actor->id,
        ]);

        $this->notificar($billetaje);
        $this->notificaciones->enviar($this->hierarchy->controladoresDe($boveda), new BilletajeSolicitadoNotification($billetaje));

        return $billetaje;
    }

    public function aprobar(
        Billetaje $billetaje,
        User $aprobador,
        string $medioEgreso = 'efectivo',
        ?string $canalEgreso = null,
        ?int $cuentaBancariaId = null,
    ): Billetaje {
        $this->asegurarPendiente($billetaje);

        return DB::transaction(function () use ($billetaje, $aprobador, $medioEgreso, $canalEgreso, $cuentaBancariaId): Billetaje {
            if ($medioEgreso === 'cuenta_bancaria') {
                $this->aprobarPorCuentaBancaria($billetaje, $aprobador, $canalEgreso, $cuentaBancariaId);
            } else {
                $this->aprobarEnEfectivo($billetaje, $aprobador);
            }

            $bovedaFresca = $billetaje->boveda->fresh(['cicloAbierto']);
            BovedaActualizada::dispatch(
                $billetaje->boveda,
                $bovedaFresca->cicloAbierto ? $this->cuentaBancariaService->saldoTotalBoveda($bovedaFresca)['total'] : null,
                $this->hierarchy->controladoresDe($billetaje->boveda),
            );

            $billetaje->update([
                'estado' => 'aprobado',
                'aprobado_por' => $aprobador->id,
                'medio_egreso' => $medioEgreso,
                'canal_egreso' => $canalEgreso,
                'cuenta_bancaria_id' => $cuentaBancariaId,
                'fecha_resolucion' => now(),
            ]);

            $billetaje = $billetaje->fresh();
            $this->notificar($billetaje);
            $this->notificaciones->enviar(collect([$billetaje->solicitadoPor]), new BilletajeAprobadoNotification($billetaje));

            return $billetaje;
        });
    }

    /**
     * Cash path (the historical, still-default behaviour): the cash leaves
     * the bóveda's ciclo AND lands as physical cash in the caja — both
     * ledgers move together, exactly as before this method was split out.
     */
    private function aprobarEnEfectivo(Billetaje $billetaje, User $aprobador): void
    {
        $bovedaCiclo = $this->bovedaService->asegurarAbierta($billetaje->boveda, $aprobador);
        $saldoActual = $this->bovedaService->calcularSaldo($bovedaCiclo);

        if (bccomp($billetaje->monto, $saldoActual, 2) > 0) {
            throw new DomainException('La bóveda no tiene saldo suficiente para entregar este billetaje.');
        }

        CajaMovimiento::query()->create([
            'caja_ciclo_id' => $billetaje->caja_ciclo_id,
            'empresa_id' => $billetaje->empresa_id,
            'tipo' => 'billetaje',
            'monto' => $billetaje->monto,
            'concepto' => 'Billetaje aprobado',
            'billetaje_id' => $billetaje->id,
            'registrado_por' => $aprobador->id,
            'fecha_caja' => $billetaje->cajaCiclo->fecha,
        ]);

        $ciclo = $billetaje->cajaCiclo;
        CajaActualizada::dispatch($ciclo->caja, $ciclo->saldoActual());

        BovedaMovimiento::query()->create([
            'boveda_ciclo_id' => $bovedaCiclo->id,
            'empresa_id' => $bovedaCiclo->empresa_id,
            'tipo' => 'egreso',
            'monto' => $billetaje->monto,
            'concepto' => 'Billetaje entregado',
            'billetaje_id' => $billetaje->id,
            'caja_ciclo_id' => $billetaje->caja_ciclo_id,
            'registrado_por' => $aprobador->id,
            'fecha_boveda' => $bovedaCiclo->fecha,
        ]);
    }

    /**
     * Bank path (yape/plin/transferencia/depósito): the money never becomes
     * physical cash, so the bóveda side only moves through the cuenta
     * bancaria's own ledger (no BovedaMovimiento). It DOES still land in the
     * actor's caja as a 'billetaje' CajaMovimiento tagged medio=cuenta_bancaria
     * — it's real money they now have to work with (can fund a desembolso,
     * etc. — see CajaCiclo::saldoActual()) — but CajaCiclo::saldoEfectivo()
     * excludes it, so the cierre screen's monto_contado comparison isn't
     * thrown off by cash that was never physically handed over.
     */
    private function aprobarPorCuentaBancaria(Billetaje $billetaje, User $aprobador, ?string $canalEgreso, ?int $cuentaBancariaId): void
    {
        if ($cuentaBancariaId === null) {
            throw new DomainException('Debes seleccionar la cuenta bancaria de la que sale el dinero.');
        }

        $cuenta = $this->cuentaBancariaService->perteneceABoveda($billetaje->boveda, $cuentaBancariaId);

        if ($canalEgreso === 'yape' && ! $cuenta->acepta_yape) {
            throw new DomainException('La cuenta bancaria seleccionada no está afiliada a Yape.');
        }

        if ($canalEgreso === 'plin' && ! $cuenta->acepta_plin) {
            throw new DomainException('La cuenta bancaria seleccionada no está afiliada a Plin.');
        }

        if (bccomp($billetaje->monto, $cuenta->saldoActual(), 2) > 0) {
            throw new DomainException('La cuenta bancaria no tiene saldo suficiente para entregar este billetaje.');
        }

        $this->cuentaBancariaService->registrarMovimiento(
            $cuenta,
            $aprobador,
            'egreso',
            $billetaje->monto,
            'Billetaje entregado por '.$canalEgreso,
            'billetaje',
        );

        $ciclo = $billetaje->cajaCiclo;

        CajaMovimiento::query()->create([
            'caja_ciclo_id' => $ciclo->id,
            'empresa_id' => $billetaje->empresa_id,
            'tipo' => 'billetaje',
            'medio' => 'cuenta_bancaria',
            'canal' => $canalEgreso,
            'monto' => $billetaje->monto,
            'concepto' => 'Billetaje aprobado por '.$canalEgreso,
            'billetaje_id' => $billetaje->id,
            'registrado_por' => $aprobador->id,
            'fecha_caja' => $ciclo->fecha,
        ]);

        CajaActualizada::dispatch($ciclo->caja, $ciclo->fresh()->saldoActual());
    }

    public function rechazar(Billetaje $billetaje, User $aprobador, ?string $motivo = null): Billetaje
    {
        $this->asegurarPendiente($billetaje);

        $billetaje->update([
            'estado' => 'rechazado',
            'aprobado_por' => $aprobador->id,
            'motivo_rechazo' => $motivo,
            'fecha_resolucion' => now(),
        ]);

        $billetaje = $billetaje->fresh();
        $this->notificar($billetaje);

        return $billetaje;
    }

    /**
     * Broadcasts the billetaje's current state to the solicitante and to
     * whoever currently controls its bóveda — so both sides see the change
     * live, without either having to leave and re-enter the module.
     */
    private function notificar(Billetaje $billetaje): void
    {
        $destinatarios = $this->hierarchy->controladoresDe($billetaje->boveda)
            ->push($billetaje->solicitadoPor);

        BilletajeActualizado::dispatch($billetaje, $destinatarios);
    }

    /**
     * Auto-rejects every pending billetaje of a ciclo — used when a superior
     * force-closes a caja that still has unresolved requests.
     */
    public function rechazarPendientesDe(CajaCiclo $ciclo, User $superior, string $motivo): void
    {
        $ciclo->billetajes()->where('estado', 'pendiente')->get()
            ->each(fn (Billetaje $billetaje) => $this->rechazar($billetaje, $superior, $motivo));
    }

    private function asegurarPendiente(Billetaje $billetaje): void
    {
        if ($billetaje->estado !== 'pendiente') {
            throw new DomainException('Este billetaje ya fue resuelto.');
        }
    }
}
