<?php

namespace App\Modules\Planilla\Http\Controllers;

use App\Modules\Planilla\Http\Requests\StorePlanillaLineasRequest;
use App\Modules\Planilla\Http\Requests\StorePlanillaRequest;
use App\Modules\Planilla\Models\Planilla;
use App\Modules\Planilla\Models\PlanillaTrabajador;
use App\Modules\Planilla\Services\PlanillaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Planilla mensual: cabecera del periodo, líneas por trabajador y cierre.
 */
class PlanillaController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PlanillaService $planillaService) {}

    /**
     * Planillas visibles para el actor, de la más reciente a la más antigua.
     * El alcance lo fija PlanillaPolicy: administrador general y secretaria ven
     * su empresa; administrador de agencia, solo la suya.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Planilla::class);

        $planillas = Planilla::query()
            ->when($request->query('periodo'), fn ($q, $periodo) => $q->where('periodo', $periodo))
            ->with(['agencia', 'cerradaPor', 'registradoPor'])
            ->when($request->user()->hasRole('administrador_agencia'), fn ($q) => $q->where('agencia_id', $request->user()->agencia_id))
            ->when($request->user()->hasAnyRole(['administrador_general', 'secretaria']), fn ($q) => $q->where('empresa_id', $request->user()->empresa_id))
            ->latest('periodo')
            ->latest('id')
            ->get()
            ->map(fn (Planilla $p): array => $this->resumir($p));

        return $this->successResponse($planillas);
    }

    /**
     * Crea (o devuelve) la cabecera de la planilla del periodo. Idempotente a
     * propósito: si ya existe en borrador, la devuelve tal cual para que el
     * frontend pueda reintentar sin duplicar.
     */
    public function store(StorePlanillaRequest $request): JsonResponse
    {
        Gate::authorize('create', Planilla::class);

        $actor = $request->user();

        $planilla = $this->planillaService->crear(
            $actor,
            $actor->empresa_id,
            $request->validated('agencia_id') !== null ? (int) $request->validated('agencia_id') : $actor->agencia_id,
            $request->safe()->except('agencia_id'),
        );

        return $this->successResponse($this->resumir($planilla), 'Planilla registrada', 201);
    }

    /**
     * Detalle de una planilla con sus líneas y totales.
     */
    public function show(Planilla $planilla): JsonResponse
    {
        Gate::authorize('view', $planilla);

        return $this->successResponse($this->detallar($planilla));
    }

    /**
     * Registra / reemplaza las líneas de los trabajadores indicados.
     */
    public function lineas(StorePlanillaLineasRequest $request, Planilla $planilla): JsonResponse
    {
        Gate::authorize('update', $planilla);

        $planilla = $this->planillaService->registrarLineas($planilla, $request->validated('lineas'));

        return $this->successResponse($this->detallar($planilla), 'Líneas registradas');
    }

    /**
     * Cierra la planilla: congela totales y consume los descuentos del mes.
     */
    public function cerrar(Request $request, Planilla $planilla): JsonResponse
    {
        Gate::authorize('update', $planilla);

        $planilla = $this->planillaService->cerrar($request->user(), $planilla);

        return $this->successResponse($this->detallar($planilla), 'Planilla cerrada');
    }

    /**
     * Reabre una planilla confirmada para editarla. Revierte los descuentos que
     * el cierre consumió, así que editarla y volver a cerrarla no descuenta los
     * adelantos dos veces.
     */
    public function reabrir(Request $request, Planilla $planilla): JsonResponse
    {
        Gate::authorize('update', $planilla);

        $planilla = $this->planillaService->reabrir($planilla);

        return $this->successResponse($this->detallar($planilla), 'Planilla reabierta');
    }

    /**
     * @return array<string, mixed>
     */
    private function resumir(Planilla $planilla): array
    {
        return [
            'id' => $planilla->id,
            'periodo' => $planilla->periodo,
            'fecha_pago' => $planilla->fecha_pago->toDateString(),
            'agencia' => $planilla->agencia?->nombre,
            'estado' => $planilla->estado,
            'total_ingresos' => $planilla->total_ingresos,
            'total_descuentos' => $planilla->total_descuentos,
            'total_tributos_trabajador' => $planilla->total_tributos_trabajador,
            'total_neto' => $planilla->total_neto,
            'total_aportes_empleador' => $planilla->total_aportes_empleador,
            'cerrada_at' => $planilla->cerrada_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detallar(Planilla $planilla): array
    {
        return [
            ...$this->resumir($planilla),
            'trabajadores' => $planilla->trabajadores()
                ->orderBy('apellidos')
                ->get()
                ->map(fn (PlanillaTrabajador $t): array => [
                    'user_id' => $t->user_id,
                    'tipo_documento' => $t->tipo_documento,
                    'numero_documento' => $t->numero_documento,
                    'apellidos' => $t->apellidos,
                    'nombres' => $t->nombres,
                    'situacion' => $t->situacion,
                    'sueldo_base' => bcsub($t->ingresos, $t->bonificaciones, 2),
                    'bonificaciones' => $t->bonificaciones,
                    'es_afp' => $t->es_afp,
                    'es_sis' => $t->es_sis,
                    'ingresos' => $t->ingresos,
                    'descuentos_devengado' => $t->descuentos_devengado,
                    'descuentos_pagado' => $t->descuentos_pagado,
                    // Desglose de los aportes: el reporte total puede
                    // presentarlos juntos, pero el frontend quiere poder
                    // mostrar ONP y AFP por separado.
                    'onp' => $t->onp,
                    'afp' => $t->afp,
                    'essalud_trabajador' => $t->essalud_trabajador,
                    'tributos_trabajador' => $t->tributos_trabajador,
                    'essalud_empleador' => $t->essalud_empleador,
                    'sis' => $t->sis,
                    'cts' => $t->cts,
                    'aportes_empleador' => $t->aportes_empleador,
                    'neto' => $t->neto,
                ])
                ->all(),
        ];
    }
}
