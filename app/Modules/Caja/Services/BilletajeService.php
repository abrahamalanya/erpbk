<?php

namespace App\Modules\Caja\Services;

use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Models\BovedaMovimiento;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class BilletajeService
{
    public function __construct(
        private readonly CajaBovedaHierarchyService $hierarchy,
        private readonly BovedaService $bovedaService,
    ) {}

    public function solicitar(User $actor, string $monto): Billetaje
    {
        $caja = Caja::query()->where('user_id', $actor->id)->first();
        $ciclo = $caja?->cicloAbierto()->first();

        if (! $ciclo) {
            throw new DomainException('Debes aperturar tu caja antes de solicitar billetaje.');
        }

        $boveda = $this->hierarchy->bovedaFinanciadoraDe($actor);

        return Billetaje::query()->create([
            'caja_ciclo_id' => $ciclo->id,
            'boveda_id' => $boveda->id,
            'empresa_id' => $ciclo->empresa_id,
            'monto' => $monto,
            'estado' => 'pendiente',
            'solicitado_por' => $actor->id,
        ]);
    }

    public function aprobar(Billetaje $billetaje, User $aprobador): Billetaje
    {
        $this->asegurarPendiente($billetaje);

        return DB::transaction(function () use ($billetaje, $aprobador): Billetaje {
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

            $billetaje->update([
                'estado' => 'aprobado',
                'aprobado_por' => $aprobador->id,
                'fecha_resolucion' => now(),
            ]);

            return $billetaje->fresh();
        });
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

        return $billetaje->fresh();
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
