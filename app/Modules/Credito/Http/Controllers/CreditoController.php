<?php

namespace App\Modules\Credito\Http\Controllers;

use App\Modules\Credito\Http\Requests\ActualizarFechaDesembolsoCreditoRequest;
use App\Modules\Credito\Http\Requests\ActualizarInteresCreditoRequest;
use App\Modules\Credito\Http\Requests\AdendarCreditoRequest;
use App\Modules\Credito\Http\Requests\ConfirmarConformidadRequest;
use App\Modules\Credito\Http\Requests\DesembolsarCreditoRequest;
use App\Modules\Credito\Http\Requests\EnviarATiendaRequest;
use App\Modules\Credito\Http\Requests\LiquidarCreditoRequest;
use App\Modules\Credito\Http\Requests\RechazarCreditoRequest;
use App\Modules\Credito\Http\Requests\RefrendarCreditoRequest;
use App\Modules\Credito\Http\Requests\StoreCreditoRequest;
use App\Modules\Credito\Http\Requests\SubirDocumentoFirmadoRequest;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\DocumentoCredito;
use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\Credito\Services\DocumentoCreditoService;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CreditoController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CreditoService $creditoService,
        private readonly DocumentoCreditoService $documentoService,
        private readonly CreditoHierarchyService $hierarchy,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Credito::class);

        $query = Credito::query()->with(['bienes', 'vehiculos', 'cliente', 'registradoPor', 'agencia']);
        $query = $this->hierarchy->visibleQuery($query, request()->user());

        $creditos = $query->latest()->paginate(15);
        $creditos->getCollection()->each(function (Credito $credito): void {
            if ($credito->estado === 'vencido') {
                $credito->setAttribute('puede_enviar_tienda', $this->creditoService->superaEsperaMora($credito));
            }
        });

        return $this->successResponse($creditos);
    }

    /**
     * Usuarios elegibles como "supervisado por" al registrar un crédito
     * vehicular o hipotecario: administradores de agencia y supervisores.
     * Se restringe a la agencia del actor cuando este pertenece a una;
     * los roles de empresa (administrador general) ven a los de toda la
     * empresa. El TenantScope ya limita el resultado a la empresa.
     */
    public function supervisores(): JsonResponse
    {
        Gate::authorize('create', Credito::class);

        $actor = request()->user();

        $supervisores = User::query()
            ->where('estado', 'activo')
            ->when($actor->agencia_id !== null, fn ($query) => $query->where('agencia_id', $actor->agencia_id))
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['administrador_agencia', 'supervisor']))
            ->orderBy('nombre')
            ->orderBy('apellido')
            ->get(['id', 'nombre', 'apellido', 'agencia_id']);

        return $this->successResponse($supervisores);
    }

    public function store(StoreCreditoRequest $request): JsonResponse
    {
        Gate::authorize('create', Credito::class);

        $data = $request->validated();

        if (($data['interes'] ?? null) !== null) {
            Gate::authorize('creditos_prendarios.editar');
        }

        $bienes = Bien::query()->whereIn('id', $data['bien_ids'])->get();

        $credito = $this->creditoService->registrar($request->user(), $bienes, $data);

        return $this->successResponse($credito, 'Crédito registrado', 201);
    }

    public function show(Credito $credito): JsonResponse
    {
        Gate::authorize('view', $credito);

        $credito->load(['bienes.fotos', 'vehiculos.fotos', 'cliente', 'registradoPor', 'supervisadoPor', 'aprobadoPor', 'documentos', 'cuotas']);

        if (in_array($credito->estado, ['activo', 'vencido'], true)) {
            $credito->setAttribute('monto_liquidacion_sugerido', $this->creditoService->calcularMontoLiquidacion($credito));
            $credito->setAttribute('monto_refrendo_sugerido', $this->creditoService->calcularMontoRefrendo($credito));
        }

        if ($credito->estado === 'vencido') {
            $credito->setAttribute('puede_enviar_tienda', $this->creditoService->superaEsperaMora($credito));
        }

        return $this->successResponse($credito);
    }

    public function aprobar(Credito $credito): JsonResponse
    {
        Gate::authorize('aprobar', $credito);

        $credito = $this->creditoService->aprobar($credito, request()->user());

        return $this->successResponse($credito, 'Crédito aprobado');
    }

    public function rechazar(RechazarCreditoRequest $request, Credito $credito): JsonResponse
    {
        Gate::authorize('rechazar', $credito);

        $credito = $this->creditoService->rechazar($credito, $request->user(), $request->validated('motivo'));

        return $this->successResponse($credito, 'Crédito rechazado');
    }

    public function subsanar(Credito $credito): JsonResponse
    {
        Gate::authorize('subsanar', $credito);

        $credito = $this->creditoService->subsanar($credito, request()->user());

        return $this->successResponse($credito, 'Crédito reenviado a revisión');
    }

    public function desembolsar(DesembolsarCreditoRequest $request, Credito $credito): JsonResponse
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

    public function refrendar(RefrendarCreditoRequest $request, Credito $credito): JsonResponse
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

    public function liquidar(LiquidarCreditoRequest $request, Credito $credito): JsonResponse
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

    public function adendar(AdendarCreditoRequest $request, Credito $credito): JsonResponse
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

    public function actualizarInteres(ActualizarInteresCreditoRequest $request, Credito $credito): JsonResponse
    {
        Gate::authorize('editar', $credito);

        $credito = $this->creditoService->actualizarInteres($credito, $request->user(), (string) $request->validated('interes'));

        return $this->successResponse($credito, 'Tasa de interés actualizada');
    }

    public function actualizarFechaDesembolso(ActualizarFechaDesembolsoCreditoRequest $request, Credito $credito): JsonResponse
    {
        Gate::authorize('editar', $credito);

        $credito = $this->creditoService->actualizarFechaDesembolso($credito, $request->user(), (string) $request->validated('fecha_desembolso'));

        return $this->successResponse($credito, 'Fecha de desembolso actualizada');
    }

    public function revertirAprobacion(Credito $credito): JsonResponse
    {
        Gate::authorize('revertirAprobacion', $credito);

        $credito = $this->creditoService->revertirAprobacion($credito, request()->user());

        return $this->successResponse($credito, 'Aprobación revertida, el crédito vuelve a pendiente');
    }

    public function enviarATienda(EnviarATiendaRequest $request, Credito $credito): JsonResponse
    {
        // Authorization is enforced by EnviarATiendaRequest::authorize().
        $precios = collect($request->validated()['precios'])
            ->mapWithKeys(fn ($precio, $bienId): array => [(int) $bienId => $precio])
            ->all();

        $credito = $this->creditoService->enviarATienda($credito, $request->user(), $precios);

        $mensaje = $credito->estado === 'pendiente_conformidad'
            ? 'Crédito a la espera de la conformidad del notario/abogado'
            : 'Crédito enviado a la tienda';

        return $this->successResponse($credito, $mensaje);
    }

    public function confirmarConformidad(ConfirmarConformidadRequest $request, Credito $credito): JsonResponse
    {
        $credito = $this->creditoService->confirmarConformidad($credito, $request->user(), $request->file('archivo'));

        return $this->successResponse($credito, 'Conformidad registrada');
    }

    public function verDocumento(Credito $credito, DocumentoCredito $documento): Response
    {
        Gate::authorize('verDocumento', $credito);

        abort_unless($documento->credito_id === $credito->id, 404);

        return $this->documentoService->renderizar($documento);
    }

    public function verCronograma(Credito $credito): Response
    {
        Gate::authorize('view', $credito);

        return $this->documentoService->renderizarCronograma($credito);
    }

    public function marcarImpreso(Credito $credito, DocumentoCredito $documento): JsonResponse
    {
        Gate::authorize('view', $credito);

        abort_unless($documento->credito_id === $credito->id, 404);

        return $this->successResponse($this->documentoService->marcarImpreso($documento), 'Documento marcado como impreso');
    }

    public function subirDocumentoFirmado(SubirDocumentoFirmadoRequest $request, Credito $credito, DocumentoCredito $documento): JsonResponse
    {
        Gate::authorize('view', $credito);

        abort_unless($documento->credito_id === $credito->id, 404);

        $documento = $this->documentoService->subirFirmado($documento, $request->file('archivo'));
        $this->creditoService->confirmarLiquidacionSiCorresponde($credito, $documento);

        return $this->successResponse($documento, 'Documento firmado subido');
    }
}
