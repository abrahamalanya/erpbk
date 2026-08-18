<?php

namespace App\Modules\Caja\Services;

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
        $this->bovedaService->asegurarAbierta($boveda, $actor);

        return DB::transaction(fn (): CajaCiclo => CajaCiclo::query()->create([
            'caja_id' => $caja->id,
            'empresa_id' => $caja->empresa_id,
            'fecha' => now()->toDateString(),
            'estado' => 'abierta',
            'saldo_apertura' => 0,
            'abierta_at' => now(),
        ]));
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

    private function cerrarCiclo(CajaCiclo $ciclo, User $actor, string $montoContado, bool $forzado): CajaCiclo
    {
        return DB::transaction(function () use ($ciclo, $actor, $montoContado, $forzado): CajaCiclo {
            $saldoCalculado = $this->calcularSaldo($ciclo);

            $ciclo->update([
                'estado' => 'cerrada',
                'saldo_calculado_cierre' => $saldoCalculado,
                'saldo_arqueo_cierre' => $montoContado,
                'diferencia' => bcsub($montoContado, $saldoCalculado, 2),
                'cerrada_por' => $actor->id,
                'cerrada_at' => now(),
                'cierre_forzado' => $forzado,
            ]);

            if (bccomp($montoContado, '0', 2) > 0) {
                $boveda = $this->hierarchy->bovedaFinanciadoraDe($ciclo->caja->user);
                $bovedaCiclo = $this->bovedaService->asegurarAbierta($boveda, $actor);

                BovedaMovimiento::query()->create([
                    'boveda_ciclo_id' => $bovedaCiclo->id,
                    'empresa_id' => $bovedaCiclo->empresa_id,
                    'tipo' => 'ingreso',
                    'monto' => $montoContado,
                    'concepto' => 'Entrega por cierre de caja',
                    'caja_ciclo_id' => $ciclo->id,
                    'registrado_por' => $actor->id,
                    'fecha_boveda' => $bovedaCiclo->fecha,
                ]);
            }

            return $ciclo->fresh();
        });
    }

    private function calcularSaldo(CajaCiclo $ciclo): string
    {
        $ingresos = (string) $ciclo->movimientos()->whereIn('tipo', ['ingreso', 'billetaje'])->sum('monto');
        $egresos = (string) $ciclo->movimientos()->where('tipo', 'egreso')->sum('monto');

        return bcadd($ciclo->saldo_apertura, bcsub($ingresos, $egresos, 2), 2);
    }
}
