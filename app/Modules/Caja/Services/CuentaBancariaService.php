<?php

namespace App\Modules\Caja\Services;

use App\Modules\Caja\Events\BovedaActualizada;
use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\ConciliacionBancaria;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Caja\Models\CuentaBancariaMovimiento;
use App\Modules\Usuario\Models\User;
use DomainException;

final class CuentaBancariaService
{
    public function crear(Boveda $boveda, User $actor, array $datos): CuentaBancaria
    {
        $cuentaBancaria = CuentaBancaria::query()->create([
            'boveda_id' => $boveda->id,
            'empresa_id' => $boveda->empresa_id,
            'banco_id' => $datos['banco_id'],
            'numero_cuenta' => $datos['numero_cuenta'],
            'titular' => $datos['titular'],
            'tipo_cuenta' => $datos['tipo_cuenta'] ?? null,
            'moneda' => $datos['moneda'] ?? 'PEN',
            'alias' => $datos['alias'] ?? null,
            'saldo_inicial' => $datos['saldo_inicial'] ?? '0',
            'acepta_yape' => $datos['acepta_yape'] ?? false,
            'numero_yape' => $datos['numero_yape'] ?? null,
            'acepta_plin' => $datos['acepta_plin'] ?? false,
            'numero_plin' => $datos['numero_plin'] ?? null,
            'creada_por' => $actor->id,
        ]);

        $this->notificar($boveda);

        return $cuentaBancaria;
    }

    public function actualizar(CuentaBancaria $cuentaBancaria, array $datos): CuentaBancaria
    {
        $cuentaBancaria->update([
            'banco_id' => $datos['banco_id'] ?? $cuentaBancaria->banco_id,
            'numero_cuenta' => $datos['numero_cuenta'] ?? $cuentaBancaria->numero_cuenta,
            'titular' => $datos['titular'] ?? $cuentaBancaria->titular,
            'tipo_cuenta' => $datos['tipo_cuenta'] ?? $cuentaBancaria->tipo_cuenta,
            'moneda' => $datos['moneda'] ?? $cuentaBancaria->moneda,
            'alias' => $datos['alias'] ?? $cuentaBancaria->alias,
            'activa' => $datos['activa'] ?? $cuentaBancaria->activa,
            'acepta_yape' => $datos['acepta_yape'] ?? $cuentaBancaria->acepta_yape,
            'numero_yape' => $datos['numero_yape'] ?? $cuentaBancaria->numero_yape,
            'acepta_plin' => $datos['acepta_plin'] ?? $cuentaBancaria->acepta_plin,
            'numero_plin' => $datos['numero_plin'] ?? $cuentaBancaria->numero_plin,
        ]);

        // 'activa' toggles whether this account counts toward saldo_total —
        // notify unconditionally rather than diffing which field changed,
        // the badge recompute is cheap and this keeps the call site simple.
        $this->notificar($cuentaBancaria->boveda);

        return $cuentaBancaria->fresh();
    }

    public function eliminar(CuentaBancaria $cuentaBancaria): void
    {
        if ($cuentaBancaria->movimientos()->exists() || $cuentaBancaria->conciliaciones()->exists()) {
            throw new DomainException('No se puede eliminar una cuenta bancaria con movimientos o conciliaciones registradas. Desactívala en su lugar.');
        }

        $boveda = $cuentaBancaria->boveda;
        $cuentaBancaria->delete();
        $this->notificar($boveda);
    }

    /**
     * Resolves a cuenta bancaria that must belong to $boveda and be active —
     * used by BilletajeService when a billetaje is approved with medio_egreso
     * 'cuenta_bancaria'. Deliberately duplicates BovedaService's own private
     * cuentaBancariaDe() (used for inyección/traspaso) instead of exposing
     * that one, to avoid coupling BilletajeService to BovedaService just for
     * this lookup.
     */
    public function perteneceABoveda(Boveda $boveda, int $cuentaBancariaId): CuentaBancaria
    {
        $cuenta = CuentaBancaria::query()
            ->where('boveda_id', $boveda->id)
            ->where('activa', true)
            ->find($cuentaBancariaId);

        if (! $cuenta) {
            throw new DomainException('La cuenta bancaria seleccionada no pertenece a esta bóveda o está inactiva.');
        }

        return $cuenta;
    }

    /**
     * $origen/$grupoId are only ever passed by BovedaService, to mark a
     * movimiento as an inyección/traspaso (and pair the two sides of a
     * traspaso together) — left null for the plain "Registrar movimiento"
     * action an admin performs directly on the account.
     */
    public function registrarMovimiento(CuentaBancaria $cuentaBancaria, User $actor, string $tipo, string $monto, ?string $concepto, ?string $origen = null, ?string $grupoId = null): CuentaBancariaMovimiento
    {
        if ($tipo === 'egreso' && bccomp($monto, $cuentaBancaria->saldoActual(), 2) > 0) {
            throw new DomainException('La cuenta bancaria no tiene saldo suficiente para este movimiento.');
        }

        $movimiento = CuentaBancariaMovimiento::query()->create([
            'cuenta_bancaria_id' => $cuentaBancaria->id,
            'empresa_id' => $cuentaBancaria->empresa_id,
            'tipo' => $tipo,
            'monto' => $monto,
            'concepto' => $concepto,
            'origen' => $origen,
            'grupo_id' => $grupoId,
            'registrado_por' => $actor->id,
            'fecha' => now()->toDateString(),
        ]);

        $this->notificar($cuentaBancaria->boveda);

        return $movimiento;
    }

    /**
     * Removes the bank-account side of a traspaso being undone from
     * BovedaService::eliminarInyeccion() — no ciclo concept applies to a
     * cuenta bancaria, so unlike the cash side there's nothing to validate
     * here beyond the pairing already having been confirmed by the caller.
     */
    public function eliminarMovimientoDeGrupo(CuentaBancariaMovimiento $movimiento): void
    {
        $boveda = $movimiento->cuentaBancaria->boveda;
        $movimiento->delete();
        $this->notificar($boveda);
    }

    public function conciliar(CuentaBancaria $cuentaBancaria, User $actor, string $saldoBanco, ?string $observacion): ConciliacionBancaria
    {
        $saldoSistema = $cuentaBancaria->saldoActual();

        return ConciliacionBancaria::query()->create([
            'cuenta_bancaria_id' => $cuentaBancaria->id,
            'empresa_id' => $cuentaBancaria->empresa_id,
            'saldo_sistema' => $saldoSistema,
            'saldo_banco' => $saldoBanco,
            'diferencia' => bcsub($saldoBanco, $saldoSistema, 2),
            'observacion' => $observacion,
            'conciliado_por' => $actor->id,
            'fecha' => now()->toDateString(),
        ]);
    }

    /**
     * Aggregated bóveda balance: cash (from the open ciclo, same formula the
     * arqueo/cierre flow already uses) plus every active cuenta bancaria's
     * own balance. Kept as a separate computation from BovedaCiclo::saldoActual()
     * on purpose — arqueo physically counts cash only, never bank balances.
     *
     * @return array{efectivo: string, cuentas_bancarias: string, total: string}
     */
    public function saldoTotalBoveda(Boveda $boveda): array
    {
        $efectivo = $boveda->cicloAbierto?->saldoActual() ?? '0.00';

        $cuentasBancarias = $boveda->cuentasBancarias()
            ->where('activa', true)
            ->get()
            ->reduce(fn (string $total, CuentaBancaria $cuenta): string => bcadd($total, $cuenta->saldoActual(), 2), '0.00');

        return [
            'efectivo' => $efectivo,
            'cuentas_bancarias' => $cuentasBancarias,
            'total' => bcadd($efectivo, $cuentasBancarias, 2),
        ];
    }

    /**
     * Broadcasts this bóveda's updated saldo_total to every admin who
     * controls it, same event/channel BovedaService already uses for
     * apertura/cierre/inyección — a new/edited/deleted cuenta bancaria or a
     * movimiento moves saldo_total exactly like a cash movimiento does.
     * Duplicates CajaBovedaHierarchyService::controladoresDe()'s query
     * inline instead of injecting it, and duplicates the "hide the amount
     * while the ciclo is closed" ternary instead of injecting BovedaService
     * — both would create a circular dependency back through
     * CajaBovedaHierarchyService/BovedaService, which inject BovedaService
     * and (as of this change) CuentaBancariaService respectively.
     */
    private function notificar(Boveda $boveda): void
    {
        $boveda = $boveda->fresh(['cicloAbierto']);

        $destinatarios = $boveda->tipo === 'principal'
            ? User::role('administrador_general')->where('empresa_id', $boveda->empresa_id)->get()
            : User::role('administrador_agencia')->where('agencia_id', $boveda->agencia_id)->get();

        $saldoTotal = $boveda->cicloAbierto ? $this->saldoTotalBoveda($boveda)['total'] : null;

        BovedaActualizada::dispatch($boveda, $saldoTotal, $destinatarios);
    }
}
