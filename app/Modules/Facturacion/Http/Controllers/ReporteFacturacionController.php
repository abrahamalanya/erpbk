<?php

namespace App\Modules\Facturacion\Http\Controllers;

use App\Modules\Facturacion\Services\ReporteFacturacionService;
use App\Modules\Facturacion\Exports\ComprobantesEmitidosExport;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Response;
use Carbon\CarbonImmutable;

/**
 * Reportes del módulo de facturación electrónica.
 *
 * Endpoints:
 *  - resumen: contadores globales (cantidad, monto, por tipo, por estado)
 *  - emitidas: listado detallado para tabla + export Excel
 *  - por-dia: serie temporal para gráfica
 *  - por-estado: conteo por estado
 *  - por-serie: conteo por serie
 *  - por-cliente: agrupado por cliente
 *  - anulados: comprobantes anulados (local o baja)
 *  - notas-credito: notas de crédito emitidas
 *  - dashboard: contadores para el dashboard (hoy, mes, totales)
 */
class ReporteFacturacionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ReporteFacturacionService $reportes,
    ) {}

    /**
     * Resumen ejecutivo: contadores globales del rango.
     */
    public function resumen(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Modules\Facturacion\Models\Comprobante::class);

        $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ]);

        $actor = $request->user();
        $empresaId = $actor->hasRole('sistemas') ? $request->input('empresa_id', $actor->empresa_id) : $actor->empresa_id;

        $desde = $request->input('fecha_desde') ? CarbonImmutable::parse($request->input('fecha_desde')) : null;
        $hasta = $request->input('fecha_hasta') ? CarbonImmutable::parse($request->input('fecha_hasta')) : null;
        $agenciaId = $request->input('agencia_id');

        return $this->successResponse(
            $this->reportes->resumen($empresaId, $desde, $hasta, $agenciaId)
        );
    }

    /**
     * Listado detallado de comprobantes emitidos (paginado).
     */
    public function emitidas(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Modules\Facturacion\Models\Comprobante::class);

        $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'tipo_documento' => ['nullable', 'string', 'in:01,03,07'],
            'estado' => ['nullable', 'string'],
            'serie' => ['nullable', 'string', 'max:4'],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $actor = $request->user();
        $empresaId = $actor->hasRole('sistemas') ? $request->input('empresa_id', $actor->empresa_id) : $actor->empresa_id;

        $desde = $request->input('fecha_desde') ? CarbonImmutable::parse($request->input('fecha_desde')) : null;
        $hasta = $request->input('fecha_hasta') ? CarbonImmutable::parse($request->input('fecha_hasta')) : null;
        $agenciaId = $request->input('agencia_id');

        $comprobantes = $this->reportes->emitidas($empresaId, $desde, $hasta, $agenciaId);

        // Aplicar filtros adicionales en memoria (o mejor en query)
        $filtered = $comprobantes->filter(function ($c) use ($request) {
            if ($request->filled('tipo_documento') && $c->tipo_documento !== $request->input('tipo_documento')) {
                return false;
            }
            if ($request->filled('estado') && $c->estado !== $request->input('estado')) {
                return false;
            }
            if ($request->filled('serie') && $c->serie !== $request->input('serie')) {
                return false;
            }
            if ($request->filled('cliente_id') && $c->cliente_id !== $request->input('cliente_id')) {
                return false;
            }
            return true;
        });

        $porPagina = $request->input('por_pagina', 15);
        $page = $request->input('page', 1);
        $skip = ($page - 1) * $porPagina;

        $total = $filtered->count();
        $items = $filtered->skip($skip)->take($porPagina)->values();

        return $this->successResponse([
            'data' => $items,
            'meta' => [
                'total' => $total,
                'por_pagina' => $porPagina,
                'pagina_actual' => $page,
                'ultima_pagina' => (int) ceil($total / $porPagina),
            ],
        ]);
    }

    /**
     * Serie temporal por día (para gráfica).
     */
    public function porDia(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Modules\Facturacion\Models\Comprobante::class);

        $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ]);

        $actor = $request->user();
        $empresaId = $actor->hasRole('sistemas') ? $request->input('empresa_id', $actor->empresa_id) : $actor->empresa_id;

        $desde = $request->input('fecha_desde') ? CarbonImmutable::parse($request->input('fecha_desde')) : null;
        $hasta = $request->input('fecha_hasta') ? CarbonImmutable::parse($request->input('fecha_hasta')) : null;
        $agenciaId = $request->input('agencia_id');

        return $this->successResponse(
            $this->reportes->porDia($empresaId, $desde, $hasta, $agenciaId)
        );
    }

    /**
     * Conteos por estado.
     */
    public function porEstado(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Modules\Facturacion\Models\Comprobante::class);

        $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ]);

        $actor = $request->user();
        $empresaId = $actor->hasRole('sistemas') ? $request->input('empresa_id', $actor->empresa_id) : $actor->empresa_id;

        $desde = $request->input('fecha_desde') ? CarbonImmutable::parse($request->input('fecha_desde')) : null;
        $hasta = $request->input('fecha_hasta') ? CarbonImmutable::parse($request->input('fecha_hasta')) : null;
        $agenciaId = $request->input('agencia_id');

        return $this->successResponse(
            $this->reportes->porEstado($empresaId, $desde, $hasta, $agenciaId)
        );
    }

    /**
     * Conteos por serie.
     */
    public function porSerie(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Modules\Facturacion\Models\Comprobante::class);

        $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ]);

        $actor = $request->user();
        $empresaId = $actor->hasRole('sistemas') ? $request->input('empresa_id', $actor->empresa_id) : $actor->empresa_id;

        $desde = $request->input('fecha_desde') ? CarbonImmutable::parse($request->input('fecha_desde')) : null;
        $hasta = $request->input('fecha_hasta') ? CarbonImmutable::parse($request->input('fecha_hasta')) : null;
        $agenciaId = $request->input('agencia_id');

        return $this->successResponse(
            $this->reportes->porSerie($empresaId, $desde, $hasta, $agenciaId)
        );
    }

    /**
     * Reporte agrupado por cliente.
     */
    public function porCliente(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Modules\Facturacion\Models\Comprobante::class);

        $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ]);

        $actor = $request->user();
        $empresaId = $actor->hasRole('sistemas') ? $request->input('empresa_id', $actor->empresa_id) : $actor->empresa_id;

        $desde = $request->input('fecha_desde') ? CarbonImmutable::parse($request->input('fecha_desde')) : null;
        $hasta = $request->input('fecha_hasta') ? CarbonImmutable::parse($request->input('fecha_hasta')) : null;
        $agenciaId = $request->input('agencia_id');

        return $this->successResponse(
            $this->reportes->porCliente($empresaId, $desde, $hasta, $agenciaId)
        );
    }

    /**
     * Comprobantes anulados (local o baja comunicada).
     */
    public function anulados(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Modules\Facturacion\Models\Comprobante::class);

        $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ]);

        $actor = $request->user();
        $empresaId = $actor->hasRole('sistemas') ? $request->input('empresa_id', $actor->empresa_id) : $actor->empresa_id;

        $desde = $request->input('fecha_desde') ? CarbonImmutable::parse($request->input('fecha_desde')) : null;
        $hasta = $request->input('fecha_hasta') ? CarbonImmutable::parse($request->input('fecha_hasta')) : null;
        $agenciaId = $request->input('agencia_id');

        return $this->successResponse(
            $this->reportes->anulados($empresaId, $desde, $hasta, $agenciaId)
        );
    }

    /**
     * Notas de crédito emitidas.
     */
    public function notasCredito(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Modules\Facturacion\Models\Comprobante::class);

        $request->validate([
            'fecha_desde' => ['nullable', 'date'],
            'fecha_hasta' => ['nullable', 'date'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ]);

        $actor = $request->user();
        $empresaId = $actor->hasRole('sistemas') ? $request->input('empresa_id', $actor->empresa_id) : $actor->empresa_id;

        $desde = $request->input('fecha_desde') ? CarbonImmutable::parse($request->input('fecha_desde')) : null;
        $hasta = $request->input('fecha_hasta') ? CarbonImmutable::parse($request->input('fecha_hasta')) : null;
        $agenciaId = $request->input('agencia_id');

        return $this->successResponse(
            $this->reportes->notasCredito($empresaId, $desde, $hasta, $agenciaId)
        );
    }

    /**
     * Export a Excel: libro de ventas / boletas emitidas.
     */
    public function exportExcel(Request $request)
    {
        Gate::authorize('viewAny', \App\Modules\Facturacion\Models\Comprobante::class);

        $request->validate([
            'fecha_desde' => ['required', 'date'],
            'fecha_hasta' => ['required', 'date'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ]);

        $actor = $request->user();
        $empresaId = $actor->hasRole('sistemas') ? $request->input('empresa_id', $actor->empresa_id) : $actor->empresa_id;

        $desde = CarbonImmutable::parse($request->input('fecha_desde'));
        $hasta = CarbonImmutable::parse($request->input('fecha_hasta'));
        $agenciaId = $request->input('agencia_id');

        $filename = "libro-ventas-{$empresaId}-{$desde->format('Ymd')}-{$hasta->format('Ymd')}.xlsx";

        return (new ComprobantesEmitidosExport($empresaId, $desde, $hasta, $agenciaId))->download($filename);
    }

    /**
     * Dashboard: contadores para hoy, mes y totales.
     */
    public function dashboard(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', \App\Modules\Facturacion\Models\Comprobante::class);

        $actor = $request->user();
        $empresaId = $actor->hasRole('sistemas') ? $request->input('empresa_id', $actor->empresa_id) : $actor->empresa_id;
        $agenciaId = $request->input('agencia_id');

        return $this->successResponse(
            $this->reportes->dashboard($empresaId, $agenciaId)
        );
    }
}