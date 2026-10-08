<?php

namespace App\Modules\Facturacion\Http\Controllers;

use App\Modules\Credito\Models\Credito;
use App\Modules\Facturacion\Http\Requests\EmitirComprobanteRequest;
use App\Modules\Facturacion\Http\Requests\EmitirNotaCreditoRequest;
use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ComprobanteDetalle;
use App\Modules\Facturacion\Services\ComprobantePdfService;
use App\Modules\Facturacion\Services\ComprobanteService;
use App\Modules\Facturacion\Services\ComprobanteSunatService;
use App\Modules\Facturacion\Services\ConfiguracionFacturacionService;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emisión y consulta de comprobantes de pago electrónico.
 *
 * La emisión desde un crédito es el flujo principal: el usuario elige un
 * crédito cancelado y el sistema calcula, numera y arma el comprobante.
 */
class ComprobanteController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ComprobanteService $comprobantes,
        private readonly ComprobantePdfService $pdf,
        private readonly ComprobanteSunatService $sunat,
        private readonly ConfiguracionFacturacionService $configuracion,
    ) {}

    /**
     * Créditos cancelados que todavía no tienen comprobante. Es la pantalla
     * que pidió el cliente: "seleccionamos entre los créditos cancelados".
     */
    public function pendientes(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Comprobante::class);

        $request->validate([
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ]);

        $actor = $request->user();
        $empresaId = $actor->hasRole('sistemas') ? $request->input('empresa_id', $actor->empresa_id) : $actor->empresa_id;

        $creditos = $this->comprobantes->pendientesDeFacturar($empresaId, $request->input('agencia_id'));

        return $this->successResponse($creditos->map(fn (Credito $credito): array => [
            'id' => $credito->id,
            'codigo' => $credito->codigo,
            'cliente' => trim($credito->cliente->nombre.' '.$credito->cliente->apellido),
            'documento' => $credito->cliente->numero_documento,
            'monto_prestamo' => $credito->monto_prestamo,
            'estado' => $credito->estado,
            'fecha_vencimiento' => $credito->fecha_vencimiento?->toDateString(),
        ]));
    }

    /**
     * Vista previa de lo que se va a facturar, sin consumir correlativo.
     */
    public function previsualizar(Credito $credito): JsonResponse
    {
        Gate::authorize('create', Comprobante::class);

        return $this->successResponse($this->comprobantes->previsualizar($credito));
    }

    /**
     * Emite el comprobante de un crédito cancelado.
     */
    public function emitir(EmitirComprobanteRequest $request, Credito $credito): JsonResponse
    {
        Gate::authorize('create', Comprobante::class);

        $comprobante = $this->comprobantes->emitirDesdeCredito($credito, $request->user());

        return $this->successResponse([
            'id' => $comprobante->id,
            'numero' => $comprobante->numeroFormateado(),
            'tipo' => $comprobante->tipo_documento->denominacion(),
            'serie' => $comprobante->serie,
            'correlativo' => $comprobante->correlativo,
            'total' => $comprobante->total,
            'estado' => $comprobante->estado->value,
        ], 'Comprobante generado correctamente. Pendiente de envío a SUNAT.', 201);
    }

    /**
     * Listado de comprobantes emitidos, con filtros.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Comprobante::class);

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

        $query = Comprobante::query()
            ->with(['cliente', 'serieComprobante'])
            ->when($request->input('fecha_desde'), fn ($q, $f) => $q->where('fecha_emision', '>=', $f))
            ->when($request->input('fecha_hasta'), fn ($q, $f) => $q->where('fecha_emision', '<=', $f))
            ->when($request->input('tipo_documento'), fn ($q, $t) => $q->where('tipo_documento', $t))
            ->when($request->input('estado'), fn ($q, $e) => $q->where('estado', $e))
            ->when($request->input('serie'), fn ($q, $s) => $q->where('serie', $s))
            ->when($request->input('cliente_id'), fn ($q, $c) => $q->where('cliente_id', $c))
            ->when($request->input('agencia_id'), fn ($q, $a) => $q->where('agencia_id', $a))
            ->orderByDesc('fecha_emision')
            ->orderByDesc('correlativo');

        $porPagina = $request->input('por_pagina', 15);

        return $this->successResponse($query->paginate($porPagina));
    }

    public function show(Comprobante $comprobante): JsonResponse
    {
        Gate::authorize('view', $comprobante);

        $comprobante->load(['detalles', 'serieComprobante', 'cliente']);

        return $this->successResponse($comprobante);
    }

    /**
     * Anula un comprobante que todavía no se comunicó a SUNAT.
     */
    public function anular(Request $request, Comprobante $comprobante): JsonResponse
    {
        Gate::authorize('anular', Comprobante::class);

        $request->validate(['motivo' => ['required', 'string', 'max:255']]);

        $comprobante = $this->comprobantes->anular($comprobante, $request->user(), $request->input('motivo'));

        return $this->successResponse([
            'id' => $comprobante->id,
            'estado' => $comprobante->estado->value,
            'anulado_at' => $comprobante->anulado_at?->toDateTimeString(),
        ], 'Comprobante anulado.');
    }

    /**
     * Representación impresa del comprobante en PDF.
     */
    public function verPdf(Comprobante $comprobante): Response
    {
        Gate::authorize('view', $comprobante);

        return $this->pdf->renderizar($comprobante);
    }

    /**
     * Comunica la baja de un comprobante ya aceptado/observado a SUNAT.
     *
     * Diferencia con anular():
     * - anular(): solo local, para comprobantes que NO se enviaron (generado, error, rechazado)
     * - comunicarBaja(): envía a SUNAT vía sendSummary, para comprobantes ACEPTADOS/OBSERVADOS
     */
    public function comunicarBaja(Request $request, Comprobante $comprobante): JsonResponse
    {
        Gate::authorize('anular', Comprobante::class);

        $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
        ]);

        $motivo = $request->input('motivo');
        $config = $this->configuracion->resolverPara($comprobante->empresa);
        $credencial = $comprobante->empresa->credencialSunat;

        if (! $credencial || ! $credencial->estaCompleta()) {
            return $this->errorResponse('Credenciales SUNAT incompletas', 422);
        }

        if ($credencial->estaVencido()) {
            return $this->errorResponse('Certificado digital vencido', 422);
        }

        // Guardar motivo para usar en la baja
        $comprobante->update(['motivo_anulacion' => $motivo]);

        // Enviar comunicación de baja
        $respuesta = $this->sunat->comunicarBaja($comprobante, $credencial, $config->modo, $motivo);

        // Aplicar respuesta
        $this->sunat->aplicarRespuestaBaja($comprobante, $respuesta);

        $comprobante->load(['empresa', 'serieComprobante']);

        if ($respuesta['exito'] && $respuesta['cdr_codigo'] === '0') {
            return $this->successResponse([
                'id' => $comprobante->id,
                'numero' => $comprobante->numeroFormateado(),
                'estado' => $comprobante->fresh()->estado->value,
                'baja_comunicada_at' => $comprobante->fresh()->baja_comunicada_at?->toDateTimeString(),
                'ticket' => $respuesta['ticket'],
            ], 'Baja comunicada y aceptada por SUNAT');
        } elseif ($respuesta['exito'] && $respuesta['ticket']) {
            return $this->successResponse([
                'id' => $comprobante->id,
                'numero' => $comprobante->numeroFormateado(),
                'estado' => $comprobante->fresh()->estado->value,
                'ticket' => $respuesta['ticket'],
                'mensaje' => 'Baja enviada a SUNAT. CDR pendiente (ticket: '.$respuesta['ticket'].')',
            ], 'Baja enviada, CDR pendiente');
        } else {
            return $this->errorResponse('Error comunicando baja: '.($respuesta['error'] ?? $respuesta['cdr_descripcion'] ?? 'Desconocido'), 422);
        }
    }

    /**
     * Emite una nota de crédito (tipo 07) referenciando un comprobante origen.
     */
    public function emitirNotaCredito(EmitirNotaCreditoRequest $request, Comprobante $comprobante): JsonResponse
    {
        Gate::authorize('emitirNotaCredito', Comprobante::class);

        $config = $this->configuracion->resolverPara($comprobante->empresa);
        $credencial = $comprobante->empresa->credencialSunat;

        if (! $credencial || ! $credencial->estaCompleta()) {
            return $this->errorResponse('Credenciales SUNAT incompletas', 422);
        }

        if ($credencial->estaVencido()) {
            return $this->errorResponse('Certificado digital vencido', 422);
        }

        $data = $request->validated();
        $lineas = $data['lineas'];

        try {
            $notaCredito = $this->sunat->emitirNotaCredito(
                $comprobante,
                $credencial,
                $config->modo,
                $data['motivo'],
                $lineas,
                $request->user()
            );

            return $this->successResponse([
                'id' => $notaCredito->id,
                'numero' => $notaCredito->numeroFormateado(),
                'tipo' => $notaCredito->tipo_documento->denominacion(),
                'serie' => $notaCredito->serie,
                'correlativo' => $notaCredito->correlativo,
                'total' => $notaCredito->total,
                'estado' => $notaCredito->estado->value,
                'documento_origen' => $comprobante->numeroFormateado(),
            ], 'Nota de crédito emitida correctamente', 201);

        } catch (\Throwable $e) {
            return $this->errorResponse('Error emitiendo nota de crédito: '.$e->getMessage(), 422);
        }
    }

    /**
     * Consulta el CDR de un comprobante (para reintentos manuales).
     */
    public function consultarCdr(Comprobante $comprobante): JsonResponse
    {
        Gate::authorize('view', $comprobante);

        $config = $this->configuracion->resolverPara($comprobante->empresa);
        $credencial = $comprobante->empresa->credencialSunat;

        if (! $credencial || ! $credencial->estaCompleta()) {
            return $this->errorResponse('Credenciales SUNAT incompletas', 422);
        }

        // Si tiene ticket de resumen/baja, consultar ese
        if ($comprobante->resumen_diario_ticket) {
            $respuesta = $this->sunat->consultarCdrBajaResumen($comprobante->resumen_diario_ticket, $credencial, $config->modo);

            if ($respuesta['exito']) {
                $this->sunat->aplicarRespuestaBaja($comprobante, $respuesta);
            }

            return $this->successResponse($respuesta);
        }

        // Si es comprobante individual, consultar estado
        $respuesta = $this->sunat->consultarEstado($comprobante, $credencial, $config->modo);

        if ($respuesta['exito']) {
            $this->sunat->aplicarRespuesta($comprobante, $respuesta);
        }

        return $this->successResponse($respuesta);
    }

    /**
     * Reenvía un comprobante a SUNAT (reintento manual).
     */
    public function reenviar(Request $request, Comprobante $comprobante): JsonResponse
    {
        Gate::authorize('create', Comprobante::class);

        if (! $comprobante->estado->enviable()) {
            return $this->errorResponse('El comprobante no está en estado reenviable (solo generado o error)', 422);
        }

        $config = $this->configuracion->resolverPara($comprobante->empresa);
        $credencial = $comprobante->empresa->credencialSunat;

        if (! $credencial || ! $credencial->estaCompleta()) {
            return $this->errorResponse('Credenciales SUNAT incompletas', 422);
        }

        if ($credencial->estaVencido()) {
            return $this->errorResponse('Certificado digital vencido', 422);
        }

        // Generar XML/ZIP y reenviar
        $respuesta = $this->sunat->emitir($comprobante, $credencial, $config->modo);
        $this->sunat->aplicarRespuesta($comprobante, $respuesta);

        return $this->successResponse([
            'id' => $comprobante->id,
            'numero' => $comprobante->numeroFormateado(),
            'estado' => $comprobante->fresh()->estado->value,
            'cdr_codigo' => $respuesta['cdr_codigo'] ?? null,
            'cdr_descripcion' => $respuesta['cdr_descripcion'] ?? null,
        ], $respuesta['exito'] ? 'Comprobante reenviado correctamente' : 'Error reenviando: '.($respuesta['error'] ?? 'Desconocido'));
    }
}
