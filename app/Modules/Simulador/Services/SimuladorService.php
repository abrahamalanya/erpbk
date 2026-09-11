<?php

namespace App\Modules\Simulador\Services;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Services\ConfiguracionCreditoService;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\Simulador\Models\SimulacionCredito;
use App\Modules\Usuario\Models\User;
use DomainException;

final class SimuladorService
{
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
     * @param  array{tipo_credito: string, cliente_id: int, monto_prestamo: string, interes?: string|null, tipo_cuota: string, numero_cuotas?: int|null}  $datos
     */
    public function simular(User $actor, array $datos): SimulacionCredito
    {
        $agencia = $actor->agencia;

        if ($agencia === null) {
            throw new DomainException('Debes pertenecer a una agencia para simular créditos.');
        }

        $configuracion = $this->configuracion->resolverPara($agencia, $datos['tipo_credito']);
        $interes = $datos['interes'] ?? (string) $configuracion->interes_default;
        $numeroCuotas = isset($datos['numero_cuotas']) && $datos['numero_cuotas'] !== null
            ? (int) $datos['numero_cuotas']
            : null;

        $preview = $this->creditoService->previsualizarCronograma(
            (string) $datos['monto_prestamo'],
            $interes,
            $datos['tipo_cuota'],
            $numeroCuotas,
        );

        $montoTotalPagar = collect($preview['cuotas'])->reduce(
            fn (string $carry, array $cuota): string => bcadd($carry, $cuota['monto_total'], 2),
            '0'
        );

        $cliente = Cliente::query()->findOrFail($datos['cliente_id']);

        return SimulacionCredito::query()->create([
            'empresa_id' => $agencia->empresa_id,
            'agencia_id' => $agencia->id,
            'tipo_credito' => $datos['tipo_credito'],
            'cliente_id' => $cliente->id,
            'registrado_por' => $actor->id,
            'monto_prestamo' => $datos['monto_prestamo'],
            'interes' => $interes,
            'tipo_cuota' => $datos['tipo_cuota'],
            'numero_cuotas' => $numeroCuotas,
            'plazo_dias' => $preview['plazo_dias'],
            'fecha_base' => $preview['fecha_base'],
            'monto_total_pagar' => $montoTotalPagar,
            'cronograma' => $preview['cuotas'],
        ]);
    }
}
