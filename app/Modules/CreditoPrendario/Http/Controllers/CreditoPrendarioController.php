<?php

namespace App\Modules\CreditoPrendario\Http\Controllers;

use App\Modules\CreditoPrendario\Http\Requests\RechazarCreditoRequest;
use App\Modules\CreditoPrendario\Http\Requests\RefrendarCreditoRequest;
use App\Modules\CreditoPrendario\Http\Requests\StoreCreditoPrendarioRequest;
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
use Illuminate\Support\Facades\Storage;

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

        $query = CreditoPrendario::query()->with(['bienes', 'cliente', 'registradoPor']);
        $query = $this->hierarchy->visibleQuery($query, request()->user());

        return $this->successResponse($query->latest()->paginate(15));
    }

    public function store(StoreCreditoPrendarioRequest $request): JsonResponse
    {
        Gate::authorize('create', CreditoPrendario::class);

        $data = $request->validated();
        $bienes = Bien::query()->whereIn('id', $data['bien_ids'])->get();

        $credito = $this->creditoService->registrar($request->user(), $bienes, $data);

        return $this->successResponse($credito, 'Crédito registrado', 201);
    }

    public function show(CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('view', $credito);

        return $this->successResponse($credito->load(['bienes.fotos', 'cliente', 'registradoPor', 'aprobadoPor', 'documentos']));
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

    public function firmar(CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('firmar', $credito);

        $credito = $this->creditoService->firmar($credito, request()->user());

        return $this->successResponse($credito, 'Crédito firmado y activado');
    }

    public function refrendar(RefrendarCreditoRequest $request, CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('refrendar', $credito);

        $nuevo = $this->creditoService->refrendar($credito, $request->user(), (string) $request->validated('monto_interes_pagado'));

        return $this->successResponse($nuevo, 'Crédito refrendado', 201);
    }

    public function liquidar(CreditoPrendario $credito): JsonResponse
    {
        Gate::authorize('liquidar', $credito);

        $credito = $this->creditoService->liquidar($credito, request()->user());

        return $this->successResponse($credito, 'Crédito liquidado');
    }

    public function descargarDocumento(CreditoPrendario $credito, DocumentoCreditoPrendario $documento): JsonResponse
    {
        Gate::authorize('view', $credito);

        abort_unless($documento->credito_id === $credito->id, 404);

        return $this->successResponse(['url' => Storage::disk('public')->url($documento->pdf_path)]);
    }

    public function marcarImpreso(CreditoPrendario $credito, DocumentoCreditoPrendario $documento): JsonResponse
    {
        Gate::authorize('view', $credito);

        abort_unless($documento->credito_id === $credito->id, 404);

        return $this->successResponse($this->documentoService->marcarImpreso($documento), 'Documento marcado como impreso');
    }

    public function marcarFirmadoDocumento(CreditoPrendario $credito, DocumentoCreditoPrendario $documento): JsonResponse
    {
        Gate::authorize('view', $credito);

        abort_unless($documento->credito_id === $credito->id, 404);

        return $this->successResponse($this->documentoService->marcarFirmado($documento), 'Documento marcado como firmado');
    }
}
