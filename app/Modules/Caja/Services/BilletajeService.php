<?php

namespace App\Modules\Caja\Services;

use App\Modules\Caja\Events\BilletajeActualizado;
use App\Modules\Caja\Events\BovedaActualizada;
use App\Modules\Caja\Events\CajaActualizada;
use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\BovedaMovimiento;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Caja\Notifications\BilletajeAprobadoNotification;
use App\Modules\Caja\Notifications\BilletajeSolicitadoNotification;
use App\Modules\Sistemas\Services\NotificacionService;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class BilletajeService
{
    public function __construct(
        private readonly CajaBovedaHierarchyService $hierarchy,
        private readonly BovedaService $bovedaService,
        private readonly NotificacionService $notificaciones,
        private readonly CuentaBancariaService $cuentaBancariaService,
    ) {}

    /**
     * Envía un billetaje de un administrador (sistemas / administrador_general
     * / administrador_agencia) hacia la caja de un asesor. El administrador ya
     * define en este paso TODO el egreso (medio, canal, cuenta y vaucher); el
     * asesor solo podrá aceptarlo o rechazarlo (ver aceptar()/rechazar()).
     *
     * El dinero todavía no se mueve: recién al aceptar el asesor se registran
     * los movimientos (aprobarEnEfectivo()/aprobarPorCuentaBancaria()).
     */
    public function enviar(
        User $emisor,
        User $asesor,
        string $monto,
        string $motivo,
        string $medioEgreso = 'efectivo',
        ?string $canalEgreso = null,
        ?int $cuentaBancariaId = null,
        ?UploadedFile $comprobante = null,
        ?int $clienteId = null,
    ): Billetaje {
        $ciclo = $this->cicloAbiertoDe($asesor);

        $boveda = $this->hierarchy->bovedaFinanciadoraDe($asesor);

        if ($medioEgreso === 'cuenta_bancaria') {
            // Resolves/validates the cuenta now, so the administrador gets
            // immediate feedback; the saldo is checked again at aceptar().
            $this->cuentaBancariaParaEgreso($boveda, $canalEgreso, $cuentaBancariaId);
        }

        $billetaje = Billetaje::query()->create([
            'caja_ciclo_id' => $ciclo->id,
            'boveda_id' => $boveda->id,
            'asesor_id' => $asesor->id,
            'empresa_id' => $ciclo->empresa_id,
            'monto' => $monto,
            'estado' => 'pendiente',
            'motivo' => $motivo,
            'cliente_id' => $clienteId,
            'medio_egreso' => $medioEgreso,
            'canal_egreso' => $canalEgreso,
            'cuenta_bancaria_id' => $cuentaBancariaId,
            'solicitado_por' => $emisor->id,
        ]);

        if ($comprobante) {
            $billetaje->fotos()->create([
                'tipo' => 'comprobante',
                'path' => $comprobante->store("billetajes/{$billetaje->id}", 'public'),
            ]);
        }

        $this->notificar($billetaje);
        $this->notificaciones->enviar(collect([$asesor]), new BilletajeSolicitadoNotification($billetaje));

        return $billetaje;
    }

    /**
     * The asesor accepts the billetaje: only here do the bóveda/caja
     * movements happen, using the egreso the administrador already defined
     * when enviando it.
     */
    public function aceptar(Billetaje $billetaje, User $asesor): Billetaje
    {
        $this->asegurarPendiente($billetaje);

        return DB::transaction(function () use ($billetaje, $asesor): Billetaje {
            if ($billetaje->medio_egreso === 'cuenta_bancaria') {
                $this->aprobarPorCuentaBancaria($billetaje, $asesor, $billetaje->canal_egreso, $billetaje->cuenta_bancaria_id);
            } else {
                $this->aprobarEnEfectivo($billetaje, $asesor);
            }

            $bovedaFresca = $billetaje->boveda->fresh(['cicloAbierto']);
            BovedaActualizada::dispatch(
                $billetaje->boveda,
                $bovedaFresca->cicloAbierto ? $this->cuentaBancariaService->saldoTotalBoveda($bovedaFresca)['total'] : null,
                $this->hierarchy->controladoresDe($billetaje->boveda),
            );

            $billetaje->update([
                'estado' => 'aprobado',
                'aprobado_por' => $asesor->id,
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
        $cuenta = $this->cuentaBancariaParaEgreso($billetaje->boveda, $canalEgreso, $cuentaBancariaId);

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

    /**
     * Resolves the cuenta bancaria an egreso must come from, validating it
     * belongs to the bóveda and is afiliada to the chosen canal.
     */
    private function cuentaBancariaParaEgreso(Boveda $boveda, ?string $canalEgreso, ?int $cuentaBancariaId): CuentaBancaria
    {
        if ($cuentaBancariaId === null) {
            throw new DomainException('Debes seleccionar la cuenta bancaria de la que sale el dinero.');
        }

        $cuenta = $this->cuentaBancariaService->perteneceABoveda($boveda, $cuentaBancariaId);

        if ($canalEgreso === 'yape' && ! $cuenta->acepta_yape) {
            throw new DomainException('La cuenta bancaria seleccionada no está afiliada a Yape.');
        }

        if ($canalEgreso === 'plin' && ! $cuenta->acepta_plin) {
            throw new DomainException('La cuenta bancaria seleccionada no está afiliada a Plin.');
        }

        return $cuenta;
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
     * Broadcasts the billetaje's current state to the asesor destino, the
     * administrador que lo envió and whoever currently controls its bóveda —
     * so all sides see the change live.
     */
    private function notificar(Billetaje $billetaje): void
    {
        $destinatarios = $this->hierarchy->controladoresDe($billetaje->boveda)
            ->push($billetaje->solicitadoPor)
            ->push($billetaje->asesor)
            ->filter();

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

    private function cicloAbiertoDe(User $asesor): CajaCiclo
    {
        $ciclo = Caja::query()->where('user_id', $asesor->id)->first()?->cicloAbierto()->first();

        if (! $ciclo) {
            throw new DomainException('El asesor no tiene su caja aperturada.');
        }

        return $ciclo;
    }

    private function asegurarPendiente(Billetaje $billetaje): void
    {
        if ($billetaje->estado !== 'pendiente') {
            throw new DomainException('Este billetaje ya fue resuelto.');
        }
    }
}
