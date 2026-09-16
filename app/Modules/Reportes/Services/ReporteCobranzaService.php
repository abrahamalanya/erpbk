<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ReporteCobranzaService
{
    public function __construct(
        private readonly CreditoHierarchyService $hierarchy,
        private readonly CreditoService $creditoService,
    ) {}

    /**
     * Cobranza diaria: un cliente aparece una vez por cada crédito suyo
     * (activo o vencido) que tenga al menos una cuota programada vencida o
     * que vence hoy — sin importar si el crédito global ya cayó en
     * 'vencido' (con varias cuotas, ej. diario a 30, las intermedias
     * vencen mucho antes de que el plazo completo lo haga). Un cliente con
     * 2 créditos sale en 2 filas, distinguidas por el código del crédito.
     *
     * Las cuotas son un cronograma proyectado — ningún tipo las marca
     * "pagada" individualmente salvo diario (pagarCuotasDiario()) y el
     * sucesor que crea pagarCuota() — así que para prendario/vehicular/
     * hipotecario/compuesto el monto realmente adeudado no es la suma de
     * esas cuotas sino el mismo cálculo que usa Cobranzas
     * (CreditoService::calcularMontoRefrendo/calcularMontoLiquidacion) — la
     * cuota vencida solo marca DESDE CUÁNDO hay mora, no CUÁNTO se debe. Para
     * diario si importa: se filtran las ya pagadas (pendientes()) para no
     * seguir listando cuotas que el cobrador ya cobró.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function cobranzaDiaria(User $actor): Collection
    {
        $hoy = now()->startOfDay()->toDateString();

        $creditos = $this->hierarchy
            ->visibleQuery(Credito::query(), $actor)
            ->whereIn('estado', ['activo', 'vencido'])
            ->whereHas('cuotas', fn (Builder $q) => $q->pendientes()->whereDate('fecha_vencimiento', '<=', $hoy))
            ->with(['cliente', 'agencia'])
            ->get()
            ->load(['cuotas' => fn ($q) => $q->pendientes()->whereDate('fecha_vencimiento', '<=', $hoy)->orderBy('fecha_vencimiento')]);

        return $creditos
            ->map(function (Credito $credito) use ($hoy): array {
                $cuotasVencidas = $credito->cuotas;
                /** @var CuotaCredito $masAntigua */
                $masAntigua = $cuotasVencidas->first();

                // Interés compuesto (solo hipotecario): calcularMontoRefrendo/
                // Liquidacion asumen el modelo de interés simple (prorateo
                // por días transcurridos) — para compuesto lo correcto es la
                // cuota fija en curso, igual que pagarCuota(). Diario tampoco
                // usa refrendo (bloqueado), sino el pago por cuotas.
                $esCompuesto = $credito->tipo_interes === 'compuesto';
                $esDiario = $credito->tipo_credito === 'diario';

                return [
                    'credito_id' => $credito->id,
                    'credito_codigo' => $credito->codigo,
                    'tipo_credito' => $credito->tipo_credito,
                    'tipo_interes' => $credito->tipo_interes,
                    'estado' => $credito->estado,
                    'cliente' => $credito->cliente,
                    'agencia' => $credito->agencia,
                    'monto_prestamo' => $credito->monto_prestamo,
                    'cuotas_vencidas' => $cuotasVencidas->count(),
                    'fecha_cuota_mas_antigua' => $masAntigua->fecha_vencimiento->toDateString(),
                    'dias_atraso' => (int) $masAntigua->fecha_vencimiento->diffInDays(now()->startOfDay()),
                    'vence_hoy' => $masAntigua->fecha_vencimiento->toDateString() === $hoy,
                    'monto_refrendo_sugerido' => $esCompuesto || $esDiario ? null : $this->creditoService->calcularMontoRefrendo($credito),
                    'monto_liquidacion_sugerido' => $esCompuesto ? null : $this->creditoService->calcularMontoLiquidacion($credito),
                    'monto_pago_cuota_sugerido' => $esCompuesto ? $this->creditoService->calcularMontoPagoCuota($credito) : null,
                    'monto_pago_cuotas_sugerido' => $esDiario ? $this->creditoService->calcularMontoPagoCuotasDiario($credito, $cuotasVencidas->count()) : null,
                ];
            })
            ->sortBy('fecha_cuota_mas_antigua')
            ->values();
    }
}
