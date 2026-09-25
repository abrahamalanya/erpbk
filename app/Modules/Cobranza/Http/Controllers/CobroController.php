<?php

namespace App\Modules\Cobranza\Http\Controllers;

use App\Modules\Caja\Models\Caja;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Http\Requests\AnularCobroRequest;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Cobranza\Services\VoucherCobroService;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\Credito\Services\DocumentoCreditoService;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Services\ExcelGeneratorService;
use App\Nucleo\Services\PdfGeneratorService;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Módulo Cobranzas: un listado transversal de todos los cobros y un punto
 * de entrada para registrarlos. El pago en sí se ejecuta con los endpoints
 * ya existentes del crédito (POST /creditos-prendarios/{credito}/refrendar
 * y /liquidar); aquí solo se listan los cobros y se resuelven los créditos
 * pendientes de un cliente con sus montos a pagar.
 *
 * index() también sirve como fuente del reporte "Historial de anulaciones"
 * del frontend (filtrando estado=anulado) — no tiene un endpoint propio bajo
 * reportes/*, reusa este listado con los filtros estado/registrado_por/
 * anulado_desde/anulado_hasta.
 */
class CobroController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CreditoHierarchyService $creditoHierarchy,
        private readonly CreditoService $creditoService,
        private readonly DocumentoCreditoService $documentoService,
        private readonly VoucherCobroService $voucherCobroService,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Cobro::class);

        $actor = request()->user();
        $porPagina = max(1, min(request()->integer('per_page', 15), 100));
        $paginado = $this->filtrarQuery($actor)->latest()->paginate($porPagina);

        // `puede_anular` evita que el frontend tenga que adivinar la regla
        // de negocio (mismo ciclo de caja, todavía abierto, del actor) —
        // ver CreditoService::anularCobro(). administrador_general se salta
        // esa regla y puede anular cualquier cobro registrado, sin importar
        // la fecha o si su ciclo de caja ya está cerrado.
        $esAdministradorGeneral = $actor->hasRole('administrador_general');
        $cicloAbiertoId = Caja::query()->where('user_id', $actor->id)->first()?->cicloAbierto()->first()?->id;

        $paginado->getCollection()->each(
            fn (Cobro $cobro) => $cobro->setAttribute(
                'puede_anular',
                $cobro->estado === 'registrado'
                    && ($esAdministradorGeneral || ($cicloAbiertoId !== null && $cobro->caja_ciclo_id === $cicloAbiertoId))
            )
        );

        return $this->successResponse($paginado);
    }

    public function pdf(PdfGeneratorService $pdf): Response
    {
        Gate::authorize('viewAny', Cobro::class);

        return $pdf->renderizarDesdeVista('reportes.tabla', [
            'titulo' => 'Cobros',
            'encabezados' => self::ENCABEZADOS,
            'filas' => $this->filtrarQuery(request()->user())->latest()->get()->map(self::mapearFila(...))->all(),
        ]);
    }

    public function excel(ExcelGeneratorService $excel): StreamedResponse
    {
        Gate::authorize('viewAny', Cobro::class);

        return $excel->generarDesdeFilas(
            'Cobros',
            self::ENCABEZADOS,
            $this->filtrarQuery(request()->user())->latest()->get()->map(self::mapearFila(...))->all(),
        );
    }

    /**
     * Mismos filtros que index() (q/operacion/estado/registrado_por/desde/
     * hasta/anulado_desde/anulado_hasta) y misma jerarquía de visibilidad —
     * lo comparten index()/pdf()/excel() para no triplicar el armado del
     * query. anulado_desde/anulado_hasta son distintos de desde/hasta (fecha
     * del cobro original): para el reporte de "historial de anulaciones" el
     * rango relevante es CUÁNDO se anuló, no cuándo se registró el cobro.
     */
    private function filtrarQuery(User $actor): Builder
    {
        $query = Cobro::query()
            ->with(['cliente', 'credito', 'registradoPor', 'anuladoPor'])
            ->whereHas('credito', fn (Builder $q) => $this->creditoHierarchy->visibleQuery($q, $actor));

        if (request()->filled('q')) {
            $termino = trim((string) request()->string('q'));
            $query->whereHas('cliente', function (Builder $sub) use ($termino): void {
                $sub->where('nombre', 'like', "%{$termino}%")
                    ->orWhere('apellido', 'like', "%{$termino}%")
                    ->orWhere('numero_documento', 'like', "%{$termino}%");
            });
        }

        if (request()->filled('operacion')) {
            $query->where('operacion', (string) request()->string('operacion'));
        }

        if (request()->filled('estado')) {
            $query->where('estado', (string) request()->string('estado'));
        }

        if (request()->filled('registrado_por')) {
            $query->where('registrado_por', request()->integer('registrado_por'));
        }

        if (request()->filled('cliente_id')) {
            $query->where('cliente_id', request()->integer('cliente_id'));
        }

        if (request()->filled('desde')) {
            $query->whereDate('created_at', '>=', request()->date('desde'));
        }

        if (request()->filled('hasta')) {
            $query->whereDate('created_at', '<=', request()->date('hasta'));
        }

        if (request()->filled('anulado_desde')) {
            $query->whereDate('anulado_at', '>=', request()->date('anulado_desde'));
        }

        if (request()->filled('anulado_hasta')) {
            $query->whereDate('anulado_at', '<=', request()->date('anulado_hasta'));
        }

        return $query;
    }

    /**
     * @var list<string>
     */
    private const ENCABEZADOS = [
        'Fecha', 'Cliente', 'Crédito', 'Operación', 'Estado', 'Monto pagado',
        'Motivo de anulación', 'Registrado por', 'Anulado por',
    ];

    /**
     * @return list<string>
     */
    private static function mapearFila(Cobro $cobro): array
    {
        return [
            $cobro->created_at->format('d/m/Y H:i'),
            $cobro->cliente ? strtoupper($cobro->cliente->nombre.' '.$cobro->cliente->apellido) : "#{$cobro->cliente_id}",
            $cobro->credito ? "#{$cobro->credito_id} · ".ucfirst($cobro->credito->tipo_credito) : "#{$cobro->credito_id}",
            ucfirst(str_replace('_', ' ', $cobro->operacion)),
            $cobro->estado === 'anulado' ? 'Anulado' : 'Registrado',
            number_format((float) $cobro->monto_pagado, 2),
            $cobro->motivo_anulacion ?? '—',
            $cobro->registradoPor ? trim($cobro->registradoPor->nombre.' '.$cobro->registradoPor->apellido) : '—',
            $cobro->anuladoPor ? trim($cobro->anuladoPor->nombre.' '.$cobro->anuladoPor->apellido) : '—',
        ];
    }

    /**
     * Créditos con deuda vigente (activo / vencido) del cliente, visibles
     * para el actor. Cada uno trae `monto_liquidacion_sugerido` (capital +
     * interés + mora) y, salvo diario, `monto_refrendo_sugerido` (solo
     * interés) — un diario trae `monto_pago_cuotas_sugerido` en su lugar, ya
     * que refrendar/adendar están bloqueados para ese tipo. Calculados igual
     * que en CreditoController::show(), para prellenar el formulario de
     * cobro.
     */
    public function creditosPendientes(Cliente $cliente): JsonResponse
    {
        Gate::authorize('registrar', Cobro::class);

        $creditos = $this->creditoHierarchy
            ->visibleQuery(Credito::query(), request()->user())
            ->where('cliente_id', $cliente->id)
            ->whereIn('estado', ['activo', 'vencido'])
            ->with(['bienes', 'vehiculos', 'inmuebles', 'agencia'])
            ->latest()
            ->get()
            ->each(fn (Credito $credito) => $this->creditoService->adjuntarMontosSugeridos($credito));

        return $this->successResponse($creditos);
    }

    /**
     * Voucher en PDF del cobro, generado por el backend a partir del voucher
     * de pago que nace junto con él. Lo abren tanto el listado de Cobranzas
     * como el formulario de cobro de Créditos apenas se registra un pago.
     * Misma visibilidad que el listado: solo cobros de créditos que el actor
     * puede ver.
     */
    public function voucher(Cobro $cobro): Response
    {
        $this->autorizarVerVoucher($cobro);

        $documento = $this->documentoService->voucherDeCobro($cobro);

        abort_if($documento === null, 404, 'Este cobro no tiene voucher (por ejemplo, porque se anuló la liquidación).');

        return $this->documentoService->renderizar($documento);
    }

    /**
     * Texto plano del voucher, para compartirlo (WhatsApp, etc.).
     */
    public function voucherTexto(Cobro $cobro): JsonResponse
    {
        $this->autorizarVerVoucher($cobro);

        return $this->successResponse(['texto' => $this->voucherCobroService->texto($cobro)]);
    }

    private function autorizarVerVoucher(Cobro $cobro): void
    {
        Gate::authorize('viewAny', Cobro::class);

        $visible = Cobro::query()
            ->whereKey($cobro->id)
            ->whereHas('credito', fn (Builder $q) => $this->creditoHierarchy->visibleQuery($q, request()->user()))
            ->exists();

        abort_unless($visible, 403, 'No tienes acceso a este cobro.');
    }

    /**
     * Anula un cobro registrado por error — solo mientras el ciclo de caja
     * donde se cobró sigue siendo el ciclo abierto del actor (ver
     * CreditoService::anularCobro()).
     */
    public function anular(AnularCobroRequest $request, Cobro $cobro): JsonResponse
    {
        Gate::authorize('anular', $cobro);

        $credito = $this->creditoService->anularCobro($cobro, $request->user(), $request->validated()['motivo'] ?? null);

        return $this->successResponse($credito->load(['bienes', 'vehiculos', 'inmuebles']), 'Cobro anulado');
    }
}
