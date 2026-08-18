<?php

namespace App\Modules\Caja\Services;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\BovedaCiclo;
use App\Modules\Caja\Models\Caja;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BovedaService
{
    /**
     * Roles whose OWN caja is funded by (and force-closed by) the empresa's
     * principal bóveda. administrador_agencia is here, not in ROLES_AGENCIA,
     * because they CONTROL the agencia bóveda for others but their own caja
     * is funded one level up — same as secretaria.
     *
     * @var list<string>
     */
    public const ROLES_PRINCIPAL = ['administrador_general', 'secretaria', 'administrador_agencia'];

    /**
     * Roles whose OWN caja is funded by (and force-closed by) their agencia's
     * bóveda, controlled by that agencia's administrador_agencia.
     *
     * @var list<string>
     */
    public const ROLES_AGENCIA = ['supervisor', 'asesor'];

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

        return DB::transaction(fn (): BovedaCiclo => BovedaCiclo::query()->create([
            'boveda_id' => $boveda->id,
            'empresa_id' => $boveda->empresa_id,
            'fecha' => now()->toDateString(),
            'estado' => 'abierta',
            'saldo_apertura' => $this->saldoInicial($boveda),
            'abierta_por' => $actor->id,
            'abierta_at' => now(),
        ]));
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

            return $ciclo->fresh();
        });
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

    private function calcularSaldo(BovedaCiclo $ciclo): string
    {
        $ingresos = (string) $ciclo->movimientos()->where('tipo', 'ingreso')->sum('monto');
        $egresos = (string) $ciclo->movimientos()->where('tipo', 'egreso')->sum('monto');

        return bcadd($ciclo->saldo_apertura, bcsub($ingresos, $egresos, 2), 2);
    }
}
