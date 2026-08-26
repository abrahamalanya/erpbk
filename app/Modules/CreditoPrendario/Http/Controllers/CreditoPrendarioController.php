<?php

namespace App\Modules\CreditoPrendario\Http\Controllers;

use App\Modules\CreditoPrendario\Http\Requests\ActualizarInteresCreditoRequest;
use App\Modules\CreditoPrendario\Http\Requests\AdendarCreditoRequest;
use App\Modules\CreditoPrendario\Http\Requests\DesembolsarCreditoRequest;
use App\Modules\CreditoPrendario\Http\Requests\LiquidarCreditoRequest;
use App\Modules\CreditoPrendario\Http\Requests\RechazarCreditoRequest;
use App\Modules\CreditoPrendario\Http\Requests\RefrendarCreditoRequest;
use App\Modules\CreditoPrendario\Http\Requests\StoreCreditoPrendarioRequest;
use App\Modules\CreditoPrendario\Http\Requests\SubirDocumentoFirmadoRequest;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\CreditoPrendario\Models\DocumentoCreditoPrendario;
use App\Modules\CreditoPrendario\Services\CreditoPrendarioHierarchyService;
use App\Modules\CreditoPrendario\Services\CreditoPrendarioService;
use App\Modules\CreditoPrendario\Services\DocumentoCreditoPrendarioService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CreditoPrendarioController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CreditoPrendarioService $creditoService,
        private readonly DocumentoCreditoPrendarioService $documentoService,
        private readonly CreditoPrendarioHierarchyService $hierarchy,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', CreditoPrendario::class);

        $query = CreditoPrendario::query()->with(['bienes', 'cliente', 'registradoPor', 'agencia']);
        $query = $this->hierarchy->visibleQuery($query, request()->user());

        $creditos = $query->latest()->paginate(15);
        $creditos->getCollection()->each(function (CreditoPrendario $credito): void {
            if ($credito->estado === 'vencido') {
                $credito->setAttribute('puede_enviar_tienda', $this->creditoService->superaEsperaMora($credito));
            }
        });

        return $this->successResponse($creditos);
    }

    public function store(StoreCreditoPrendarioRequest $request): JsonResponse
    {
        Gate::authorize('create', CreditoPrendario::class);

        $data = $request->validated();

        if (($data['interes'] ?? null) !== null) {
            Gate::authorize('creditos_prendarios.editar');
        }

        $bienes = Bien::query()->whereIn('id', $data['bien_ids'])->get();

        $credito = $this->creditoService->registrar($request->user(), $bienes, $data);

        return $this->successResponse($credito, 'Crédito registrado', 201);
    }

    public function show(CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('view', $credito);

        $credito->load(['bienes.fotos', 'cliente', 'registradoPor', 'aprobadoPor', 'documentos', 'cuotas']);

        if (in_array($credito->estado, ['activo', 'vencido'], true)) {
            $credito->setAttribute('monto_liquidacion_sugerido', $this->creditoService->calcularMontoLiquidacion($credito));
            $credito->setAttribute('monto_refrendo_sugerido', $this->creditoService->calcularMontoRefrendo($credito));
        }

        if ($credito->estado === 'vencido') {
            $credito->setAttribute('puede_enviar_tienda', $this->creditoService->superaEsperaMora($credito));
        }

        return $this->successResponse($credito);
    }

    public function aprobar(CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('aprobar', $credito);

        $credito = $this->creditoService->aprobar($credito, request()->user());

        return $this->successResponse($credito, 'Crédito aprobado');
    }

    public function rechazar(RechazarCreditoRequest $request, CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('rechazar', $credito);

        $credito = $this->creditoService->rechazar($credito, $request->user(), $request->validated('motivo'));

        return $this->successResponse($credito, 'Crédito rechazado');
    }

    public function subsanar(CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('subsanar', $credito);

        $credito = $this->creditoService->subsanar($credito, request()->user());

        return $this->successResponse($credito, 'Crédito reenviado a revisión');
    }

    public function desembolsar(DesembolsarCreditoRequest $request, CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('desembolsar', $credito);

        $data = $request->validated();

        if (($data['numero_cuotas'] ?? null) !== null || ($data['interes'] ?? null) !== null) {
            Gate::authorize('editar', $credito);
        }

        $credito = $this->creditoService->desembolsar(
            $credito,
            $request->user(),
            $data['numero_cuotas'] ?? null,
            isset($data['interes']) ? (string) $data['interes'] : null,
        );

        return $this->successResponse($credito, 'Crédito desembolsado y cronograma generado');
    }

    public function refrendar(RefrendarCreditoRequest $request, CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('refrendar', $credito);

        $data = $request->validated();

        $nuevo = $this->creditoService->refrendar(
            $credito,
            $request->user(),
            (string) $data['monto_pagado'],
            $data['medio'],
            $request->file('comprobante'),
        );

        return $this->successResponse($nuevo, 'Crédito refrendado', 201);
    }

    public function liquidar(LiquidarCreditoRequest $request, CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('liquidar', $credito);

        $data = $request->validated();

        $credito = $this->creditoService->liquidar(
            $credito,
            $request->user(),
            (string) $data['monto_pagado'],
            $data['medio'],
            $request->file('comprobante'),
        );

        return $this->successResponse($credito, 'Crédito liquidado');
    }

    public function adendar(AdendarCreditoRequest $request, CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('adendar', $credito);

        $data = $request->validated();

        if (($data['interes'] ?? null) !== null || ($data['tipo_cuota'] ?? null) !== null) {
            Gate::authorize('editar', $credito);
        }

        $nuevo = $this->creditoService->adendar(
            $credito,
            $request->user(),
            (string) $data['monto_pagado'],
            isset($data['interes']) ? (string) $data['interes'] : null,
            $data['tipo_cuota'] ?? null,
            $data['medio'],
            $request->file('comprobante'),
        );

        return $this->successResponse($nuevo, 'Crédito adendado, pendiente de aprobación', 201);
    }

    public function actualizarInteres(ActualizarInteresCreditoRequest $request, CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('editar', $credito);

        $credito = $this->creditoService->actualizarInteres($credito, $request->user(), (string) $request->validated('interes'));

        return $this->successResponse($credito, 'Tasa de interés actualizada');
    }

    public function revertirAprobacion(CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('revertirAprobacion', $credito);

        $credito = $this->creditoService->revertirAprobacion($credito, request()->user());

        return $this->successResponse($credito, 'Aprobación revertida, el crédito vuelve a pendiente');
    }

    public function enviarATienda(CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('enviarATienda', $credito);

        $credito = $this->creditoService->enviarATienda($credito, request()->user());

        return $this->successResponse($credito, 'Crédito enviado a la tienda');
    }

    public function verDocumento(CreditoPrendario $credito, DocumentoCreditoPrendario $documento): Response
    {
        Gate::authorize('verDocumento', $credito);

        abort_unless($documento->credito_id === $credito->id, 404);

        return $this->documentoService->renderizar($documento);
    }

    public function verCronograma(CreditoPrendario $credito): Response
    {
        Gate::authorize('view', $credito);

        return $this->documentoService->renderizarCronograma($credito);
    }

    public function marcarImpreso(CreditoPrendario $credito, DocumentoCreditoPrendario $documento): JsonResponse
    {
        Gate::authorize('view', $credito);

        abort_unless($documento->credito_id === $credito->id, 404);

        return $this->successResponse($this->documentoService->marcarImpreso($documento), 'Documento marcado como impreso');
    }

    public function subirDocumentoFirmado(SubirDocumentoFirmadoRequest $request, CreditoPrendario $credito, DocumentoCreditoPrendario $documento): JsonResponse
    {
        Gate::authorize('view', $credito);

        abort_unless($documento->credito_id === $credito->id, 404);

        $documento = $this->documentoService->subirFirmado($documento, $request->file('archivo'));
        $this->creditoService->confirmarLiquidacionSiCorresponde($credito, $documento);

        return $this->successResponse($documento, 'Documento firmado subido');
    }
}
