<?php

namespace App\Modules\Credito\Http\Controllers;

use App\Modules\Credito\Http\Requests\ActualizarFechaDesembolsoCreditoRequest;
use App\Modules\Credito\Http\Requests\ActualizarInteresCreditoRequest;
use App\Modules\Credito\Http\Requests\AdendarCreditoRequest;
use App\Modules\Credito\Http\Requests\ConfirmarConformidadRequest;
use App\Modules\Credito\Http\Requests\DesembolsarCreditoRequest;
use App\Modules\Credito\Http\Requests\EnviarATiendaRequest;
use App\Modules\Credito\Http\Requests\LiquidarCreditoRequest;
use App\Modules\Credito\Http\Requests\PreviewCronogramaRequest;
use App\Modules\Credito\Http\Requests\RechazarCreditoRequest;
use App\Modules\Credito\Http\Requests\RefinanciarCreditoRequest;
use App\Modules\Credito\Http\Requests\RefrendarCreditoRequest;
use App\Modules\Credito\Http\Requests\StoreCreditoRequest;
use App\Modules\Credito\Http\Requests\SubirDocumentoFirmadoRequest;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\DocumentoCredito;
use App\Modules\Credito\Services\ConfiguracionCreditoService;
use App\Modules\Credito\Services\CreditoHierarchyService;
use App\Modules\Credito\Services\CreditoService;
use App\Modules\Credito\Services\DocumentoCreditoService;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use DomainException;
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
        private readonly ConfiguracionCreditoService $configuracionService,
    ) {}

    /**
     * Interés por defecto y tope de cuotas ya resueltos (override de agencia
     * o default de empresa) por tipo de crédito, según la agencia del
     * usuario. Lo consume el formulario de registro para precargar el input
     * de interés y acotar el de número de cuotas: el asesor no tiene permiso
     * para leer la configuración completa (configuraciones_credito_prendario.ver).
     */
    public function configuracion(): JsonResponse
    {
        Gate::authorize('create', Credito::class);

        $agencia = request()->user()->agencia;

        $tipos = collect(['prendario', 'vehicular', 'hipotecario']);

        $configPorTipo = $tipos->mapWithKeys(function (string $tipo) use ($agencia): array {
            if ($agencia === null) {
                return [$tipo => null];
            }

            try {
                return [$tipo => $this->configuracionService->resolverPara($agencia, $tipo)];
            } catch (DomainException) {
                return [$tipo => null];
            }
        });

        return $this->successResponse([
            'interes_default' => $configPorTipo->map(fn ($config) => $config?->interes_default)->all(),
            'max_cuotas' => $configPorTipo->map(fn ($config): int => (int) ($config?->max_cuotas ?? 1))->all(),
        ]);
    }

    /**
     * Cronograma tentativo (fecha de desembolso = hoy) para mostrar en el
     * formulario de registro antes de que el crédito exista. No persiste
     * nada; las cuotas reales se generan al desembolsar.
     */
    public function cronogramaPreview(PreviewCronogramaRequest $request): JsonResponse
    {
        Gate::authorize('create', Credito::class);

        $data = $request->validated();

        $preview = $this->creditoService->previsualizarCronograma(
            (string) $data['monto_prestamo'],
            (string) $data['interes'],
            $data['tipo_cuota'],
            isset($data['numero_cuotas']) ? (int) $data['numero_cuotas'] : null,
        );

        return $this->successResponse($preview);
    }

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Credito::class);

        $query = Credito::query()->with(['bienes', 'vehiculos', 'inmuebles', 'cliente', 'registradoPor', 'agencia']);
        $query = $this->hierarchy->visibleQuery($query, request()->user());

        if (request()->filled('tipo_credito')) {
            $query->where('tipo_credito', (string) request()->string('tipo_credito'));
        }

        if (request()->filled('cliente_id')) {
            $query->where('cliente_id', request()->integer('cliente_id'));
        }

        if (request()->filled('estado')) {
            $query->where('estado', (string) request()->string('estado'));
        }

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

        if (($data['interes'] ?? null) !== null && ! ($data['interes_solicitud_especial'] ?? false)) {
            Gate::authorize('creditos_prendarios.editar');
        }

        $bienes = Bien::query()->whereIn('id', $data['bien_ids'])->get();

        $credito = $this->creditoService->registrar($request->user(), $bienes, $data);

        return $this->successResponse($credito, 'Crédito registrado', 201);
    }

    public function show(Credito $credito): JsonResponse
    {
        Gate::authorize('view', $credito);

        $credito->load(['bienes.fotos', 'vehiculos.fotos', 'inmuebles', 'cliente', 'aval', 'aval2', 'registradoPor', 'supervisadoPor', 'aprobadoPor', 'documentos', 'cuotas', 'expedienteDocumentos']);

        if (in_array($credito->estado, ['activo', 'vencido'], true)) {
            $credito->setAttribute('monto_liquidacion_sugerido', $this->creditoService->calcularMontoLiquidacion($credito));
            $credito->setAttribute('monto_refrendo_sugerido', $this->creditoService->calcularMontoRefrendo($credito));
        }

        if ($credito->estado === 'vencido') {
            $credito->setAttribute('puede_enviar_tienda', $this->creditoService->superaEsperaMora($credito));
        }

        return $this->successResponse($credito);
    }

    public function destroy(Credito $credito): JsonResponse
    {
        Gate::authorize('delete', $credito);

        $this->creditoService->eliminar($credito);

        return $this->successResponse(null, 'Crédito eliminado');
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

        if (($data['numero_cuotas'] ?? null) !== null || ($data['interes'] ?? null) !== null || ($data['fecha_desembolso'] ?? null) !== null) {
            Gate::authorize('editar', $credito);
        }

        $credito = $this->creditoService->desembolsar(
            $credito,
            $request->user(),
            $data['numero_cuotas'] ?? null,
            isset($data['interes']) ? (string) $data['interes'] : null,
            $data['fecha_desembolso'] ?? null,
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
            isset($data['descuento']) ? (string) $data['descuento'] : null,
            $data['motivo_descuento'] ?? null,
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
            isset($data['descuento']) ? (string) $data['descuento'] : null,
            $data['motivo_descuento'] ?? null,
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
            isset($data['descuento']) ? (string) $data['descuento'] : null,
            $data['motivo_descuento'] ?? null,
        );

        return $this->successResponse($nuevo, 'Crédito adendado, pendiente de aprobación', 201);
    }

    public function refinanciar(RefinanciarCreditoRequest $request, Credito $credito): JsonResponse
    {
        Gate::authorize('refinanciar', $credito);

        $data = $request->validated();

        $nuevo = $this->creditoService->refinanciar(
            $credito,
            $request->user(),
            isset($data['monto_pagado']) ? (string) $data['monto_pagado'] : null,
            $data['medio'],
            $request->file('comprobante'),
            isset($data['descuento']) ? (string) $data['descuento'] : null,
            $data['motivo_descuento'] ?? null,
        );

        return $this->successResponse($nuevo, 'Crédito refinanciado, pendiente de aprobación', 201);
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
        // El sticker y los vouchers son documentos de salida (no se firman):
        // el asesor puede imprimirlos apenas existe el crédito. El resto
        // (contrato / declaración / ...) sigue la regla más estricta que
        // oculta el papeleo firmable al asesor mientras está pendiente.
        Gate::authorize(
            in_array($documento->tipo, ['sticker', 'voucher_desembolso', 'voucher_pago'], true) ? 'view' : 'verDocumento',
            $credito,
        );

        abort_unless($documento->credito_id === $credito->id, 404);

        return $this->documentoService->renderizar($documento);
    }

    public function verCronograma(Credito $credito): Response
    {
        Gate::authorize('view', $credito);

        if ($credito->cuotas()->exists()) {
            return $this->documentoService->renderizarCronograma($credito);
        }

        // Aún sin cuotas persistidas (antes del desembolso): cronograma
        // tentativo con la misma fórmula, tomando hoy como fecha de desembolso
        // y el número de cuotas elegido al registrar (si el tipo lo permite).
        $preview = $this->creditoService->previsualizarCronograma(
            (string) $credito->monto_prestamo,
            (string) $credito->interes,
            $credito->tipo_cuota,
            $credito->numero_cuotas,
        );

        return $this->documentoService->renderizarCronogramaTentativo($credito, $preview);
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
        $this->creditoService->confirmarLiquidacionSiCorresponde($credito, $documento, $request->user());

        return $this->successResponse($documento, 'Documento firmado subido');
    }
}
