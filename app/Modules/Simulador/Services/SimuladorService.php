<?php

namespace App\Modules\Simulador\Services;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Services\ConfiguracionCreditoService;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\Simulador\Models\SimulacionCredito;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Validation\ValidationException;

final class SimuladorService
{
    private function configuracionDe(User $actor, string $tipo): ?ConfiguracionCredito
    {
        try {
            return $actor->agencia !== null
                ? $this->configuracion->resolverPara($actor->agencia, $tipo)
                : ($actor->empresa_id !== null ? $this->configuracion->resolverParaEmpresa($actor->empresa_id, $tipo) : null);
        } catch (DomainException) {
            return null;
        }
    }

    public function configuracionPara(User $actor): array
    {
        $resultado = [];
        foreach (['prendario', 'vehicular', 'hipotecario', 'diario'] as $tipo) {
            $config = $this->configuracionDe($actor, $tipo);
            $resultado[$tipo] = [
                'interes_default' => $config?->interes_default,
                'max_cuotas' => min($config?->max_cuotas ?? 600, 600),
            ];
        }

        return $resultado;
    }

    public function calcular(User $actor, array $datos): array
    {
        $modo = $datos['modo_calculo'] ?? 'numero_cuotas';
        $config = $this->configuracionDe($actor, $datos['tipo_credito']);
        $interes = $datos['interes'] ?? $config?->interes_default;
        if ($interes === null) {
            throw ValidationException::withMessages(['interes' => 'Indica una tasa de interés para simular sin configuración.']);
        }
        $n = isset($datos['numero_cuotas']) ? (int) $datos['numero_cuotas'] : null;
        $maxCuotas = min($config?->max_cuotas ?? 600, 600);
        if ($n !== null && $n > $maxCuotas) {
            throw ValidationException::withMessages(['numero_cuotas' => 'El número de cuotas supera el máximo configurado.']);
        }
        $tipoInteres = $datos['tipo_interes'] ?? 'simple';
        try {
            $preview = $modo === 'importe_cuota'
                ? $this->creditoService->previsualizarCronogramaPorImporte(
                    (string) $datos['monto_prestamo'], (string) $interes, $datos['tipo_cuota'],
                    (string) $datos['importe_cuota'], $tipoInteres, $datos['tipo_credito'], $maxCuotas,
                )
                : $this->creditoService->previsualizarCronograma(
                    (string) $datos['monto_prestamo'], (string) $interes, $datos['tipo_cuota'], $n, $tipoInteres, $datos['tipo_credito'], ajustarUltimaCuota: true,
                );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([$modo === 'importe_cuota' ? 'importe_cuota' : 'numero_cuotas' => $exception->getMessage()]);
        }
        if ($modo === 'importe_cuota') {
            $n = count($preview['cuotas']);
        }
        $saldo = bcadd((string) $datos['monto_prestamo'], '0', 2);
        $total = '0.00';
        $totalInteres = '0.00';
        $filas = [];
        foreach ($preview['cuotas'] as $cuota) {
            if (bccomp($cuota['monto_capital'], '0', 2) <= 0 || bccomp($cuota['monto_interes'], '0', 2) < 0) {
                throw ValidationException::withMessages(['numero_cuotas' => 'Los parámetros generan cuotas sin amortización o intereses negativos. Reduce el número de cuotas o revisa el monto.']);
            }
            $saldo = bcsub($saldo, $cuota['monto_capital'], 2);
            $total = bcadd($total, $cuota['monto_total'], 2);
            $totalInteres = bcadd($totalInteres, $cuota['monto_interes'], 2);
            $filas[] = [...$cuota, 'saldo_pendiente' => $saldo];
        }
        if (bccomp($total, '9999999999.99', 2) > 0) {
            throw ValidationException::withMessages(['monto_prestamo' => 'El total calculado supera el importe admitido.']);
        }

        return [
            'tipo_credito' => $datos['tipo_credito'],
            'monto_prestamo' => bcadd((string) $datos['monto_prestamo'], '0', 2),
            'interes' => bcadd((string) $interes, '0', 2),
            'tipo_interes' => $tipoInteres,
            'tipo_cuota' => $datos['tipo_cuota'],
            'modo_calculo' => $modo,
            'importe_cuota' => $modo === 'importe_cuota' ? bcadd((string) $datos['importe_cuota'], '0', 2) : null,
            'numero_cuotas' => $n,
            'numero_cuotas_calculadas' => count($filas),
            'fecha_base' => $preview['fecha_base'],
            'plazo_dias' => $preview['plazo_dias'],
            'monto_total_pagar' => $total,
            'total_interes' => $totalInteres,
            'saldo_residual' => $saldo,
            'cronograma' => $filas,
        ];
    }

    public function __construct(
        private readonly CreditoService $creditoService,
        private readonly ConfiguracionCreditoService $configuracion,
    ) {}

    /**
     * Calcula y persiste una simulación de crédito para mostrarle al cliente
     * cómo sería su cronograma de pagos, sin crear un crédito real. Reutiliza
     * el mismo motor de cálculo que el preview de registro
     * (CreditoService::previsualizarCronograma()); a diferencia de aquel, el
     * interés es libre (no requiere permiso especial) porque no compromete
     * nada — es justamente el punto del simulador, poder jugar con distintas
     * tasas/cuotas frente al cliente.
     *
     * @param  array{tipo_credito: string, cliente_id?: int|null, monto_prestamo: string, interes?: string|null, tipo_interes?: string|null, tipo_cuota: string, numero_cuotas?: int|null, modo_calculo?: string, importe_cuota?: string|null}  $datos
     */
    public function simular(User $actor, array $datos): SimulacionCredito
    {
        $resultado = $this->calcular($actor, $datos);
        $cliente = isset($datos['cliente_id']) ? Cliente::query()->findOrFail($datos['cliente_id']) : null;
        $empresaId = $actor->empresa_id ?? ($actor->hasRole('sistemas') ? $cliente?->empresa_id : null);
        if ($empresaId === null) {
            throw ValidationException::withMessages(['cliente_id' => 'Para guardar la simulación necesitas una empresa o un cliente de referencia.']);
        }
        unset($resultado['total_interes'], $resultado['saldo_residual'], $resultado['numero_cuotas_calculadas']);

        return SimulacionCredito::query()->create([
            ...$resultado,
            'empresa_id' => $empresaId,
            'agencia_id' => $actor->agencia_id,
            'cliente_id' => $cliente?->id,
            'registrado_por' => $actor->id,
        ]);
    }
}
