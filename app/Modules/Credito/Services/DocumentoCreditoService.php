<?php

namespace App\Modules\Credito\Services;

use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Credito\Models\DocumentoCredito;
use App\Modules\Credito\Tipos\CreditoTipoManager;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Services\PdfGeneratorService;
use DomainException;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class DocumentoCreditoService
{
    /**
     * @var list<string>
     */
    private const TIPOS_DOCUMENTO = [
        'contrato', 'declaracion', 'adenda', 'fotos', 'devolucion',
        'voucher_desembolso', 'voucher_pago', 'sticker',
        'carta_no_adeudo', 'recepcion_vehiculos', 'ficha_socioeconomica',
        'notificacion_pago', 'aviso_prejudicial', 'saldo_deudor', 'expediente',
        'contrato_transferencia', 'pagare',
    ];

    public function __construct(
        private readonly PdfGeneratorService $pdfGenerator,
        private readonly CreditoTipoManager $tipos,
        private readonly ConfiguracionCreditoService $configuracion,
        private readonly LiquidacionSaldoDeudorService $liquidacion,
    ) {}

    /**
     * Documentos de plantilla: no guardan datos propios, se arman en vivo
     * desde el crédito cada vez que se ven, y cuáles corresponden depende
     * solo del tipo y del estado del crédito. La fila en
     * documentos_credito_prendario de cada uno solo existe para llevar su
     * estado (impreso, firmado y el escaneo firmado), así que se crea cuando
     * falta — ver documentosDe().
     *
     * - contrato + declaración: todos menos diario.
     * - pagaré: solo diario, y recién desembolsado — necesita el cronograma
     *   real (montos y fechas exactos de cada cuota), no uno tentativo.
     * - fotos: la constancia fotográfica de las garantías, para los tipos con
     *   prenda física.
     * - sticker: etiqueta del producto en tienda, para los tipos que la usan.
     * - recepción de vehículos: solo el crédito vehicular original; sus
     *   sucesores conservan el vehículo ya recibido.
     * - hipotecario: ficha socioeconómica, documentos de cobranza
     *   (notificación de pago, aviso prejudicial, liquidación del saldo
     *   deudor) y expediente.
     *
     * @return list<string>
     */
    public function tiposPlantilla(Credito $credito): array
    {
        $tipo = $this->tipos->paraCredito($credito);
        $esOriginal = $credito->refrendo_de_credito_id === null
            && $credito->adenda_de_credito_id === null
            && $credito->refinanciamiento_de_credito_id === null
            && $credito->pago_cuota_de_credito_id === null;

        return array_values(array_filter([
            $credito->tipo_credito !== 'diario' ? 'contrato' : null,
            $credito->tipo_credito !== 'diario' ? 'declaracion' : null,
            $credito->tipo_credito === 'diario' && $credito->fecha_desembolso ? 'pagare' : null,
            $tipo->generaFotosGarantia() ? 'fotos' : null,
            $tipo->generaStickerGarantia() ? 'sticker' : null,
            $credito->tipo_credito === 'vehicular' && $esOriginal ? 'recepcion_vehiculos' : null,
            ...($credito->tipo_credito === 'hipotecario'
                ? ['ficha_socioeconomica', 'notificacion_pago', 'aviso_prejudicial', 'saldo_deudor', 'expediente']
                : []),
        ]));
    }

    /**
     * Crea las filas de los documentos de plantilla que el crédito aún no
     * tiene. Idempotente: se llama al registrar o encadenar un crédito, al
     * desembolsarlo (para el pagaré) y al leer sus documentos.
     */
    public function generarPlantillas(Credito $credito, ?User $actor = null): void
    {
        DB::transaction(function () use ($credito, $actor): void {
            Credito::query()->whereKey($credito->id)->lockForUpdate()->first();

            $existentes = $credito->documentos()->pluck('tipo')->all();

            foreach (array_diff($this->tiposPlantilla($credito), $existentes) as $tipo) {
                DocumentoCredito::query()->create([
                    'credito_id' => $credito->id,
                    'empresa_id' => $credito->empresa_id,
                    'tipo' => $tipo,
                    'generado_por' => $actor?->id ?? $credito->registrado_por,
                    'generado_at' => $actor ? now() : ($credito->created_at ?? now()),
                ]);
            }
        });
    }

    /**
     * Los documentos del crédito para mostrarlos: completa los de plantilla
     * que falten (créditos importados o anteriores a un documento nuevo) y
     * devuelve todos, incluidos los de evento (vouchers, devolución, carta de
     * no adeudo, contrato de transferencia).
     *
     * @return EloquentCollection<int, DocumentoCredito>
     */
    public function documentosDe(Credito $credito): EloquentCollection
    {
        $this->generarPlantillas($credito);

        return $credito->documentos()->get();
    }

    public function generarAdenda(Credito $credito, User $actor): DocumentoCredito
    {
        return $this->generar($credito, $actor, 'adenda');
    }

    /**
     * El acta de devolución de bienes — generada al liquidar, su firma
     * escaneada es la confirmación de que los bienes fueron físicamente
     * devueltos al cliente (ver CreditoService::liquidar()/
     * confirmarLiquidacionSiCorresponde()).
     */
    public function generarDevolucion(Credito $credito, User $actor): DocumentoCredito
    {
        return $this->generar($credito, $actor, 'devolucion');
    }

    /**
     * Carta de no adeudo — se genera cuando el crédito queda liquidado por
     * completo (acta de devolución firmada); certifica que el cliente ya no
     * mantiene deuda con la empresa.
     */
    public function generarCartaNoAdeudo(Credito $credito, User $actor): DocumentoCredito
    {
        return $this->generar($credito, $actor, 'carta_no_adeudo');
    }

    /**
     * Voucher de desembolso — snapshot del monto entregado, medio, fechas y
     * cronograma resultante en el momento del desembolso.
     *
     * @param  array<string, mixed>  $datos
     */
    public function generarVoucherDesembolso(Credito $credito, User $actor, array $datos): DocumentoCredito
    {
        return $this->generar($credito, $actor, 'voucher_desembolso', $datos);
    }

    /**
     * Voucher detallado de un cobro (refrendo / adenda / liquidación) —
     * snapshot del monto pagado y su desglose (interés, capital, mora,
     * vuelto) que no se puede re-derivar del crédito después.
     *
     * @param  array<string, mixed>  $datos
     */
    public function generarVoucherPago(Credito $credito, User $actor, array $datos): DocumentoCredito
    {
        return $this->generar($credito, $actor, 'voucher_pago', $datos, $datos['cobro_id'] ?? null);
    }

    /**
     * Voucher del cobro `$cobro`: el enlazado por `cobro_id` o, para los
     * cobros anteriores a ese enlace, el voucher de pago del mismo crédito
     * generado en el mismo momento (el voucher nace en la misma transacción
     * que el cobro). Null si no existe — por ejemplo, la liquidación anulada
     * borra sus vouchers.
     */
    public function voucherDeCobro(Cobro $cobro): ?DocumentoCredito
    {
        $enlazado = DocumentoCredito::query()->where('tipo', 'voucher_pago')->where('cobro_id', $cobro->id)->first();

        if ($enlazado) {
            return $enlazado;
        }

        return DocumentoCredito::query()
            ->where('tipo', 'voucher_pago')
            ->where('credito_id', $cobro->credito_id)
            ->whereNull('cobro_id')
            ->whereBetween('generado_at', [$cobro->created_at->copy()->subMinute(), $cobro->created_at->copy()->addMinute()])
            ->orderBy('generado_at')
            ->first();
    }

    /**
     * Contrato de transferencia del vehículo ejecutado a un tercero
     * comprador — solo vehicular, se genera al cerrar la venta
     * (CreditoService::vender()). Los datos del comprador y el precio/pagos
     * son propios de esta operación puntual (no columnas del crédito), así
     * que se guardan en el documento como cualquier voucher.
     *
     * @param  array<string, mixed>  $datos
     */
    public function generarContratoTransferencia(Credito $credito, User $actor, array $datos): DocumentoCredito
    {
        return $this->generar($credito, $actor, 'contrato_transferencia', $datos);
    }

    /**
     * Renders the PDF fresh from the crédito's current data — nothing is
     * kept on disk, so this runs again on every "ver documento" request.
     */
    public function renderizar(DocumentoCredito $documento): Response
    {
        if (! in_array($documento->tipo, self::TIPOS_DOCUMENTO, true)) {
            throw new DomainException("Tipo de documento desconocido: {$documento->tipo}");
        }

        $credito = $documento->credito()->with([
            'cliente.fichaSocioeconomica.familiares', 'cliente.fotos', 'cuotas', 'aval', 'aval2',
            'expedienteDocumentos', 'inmuebles', 'agencia', 'empresa', 'registradoPor',
        ])->firstOrFail();

        $tipo = $this->tipos->paraCredito($credito);
        $garantias = $credito->garantiasComo($tipo->garantiaModelo())->with('fotos')->get();

        // El expediente incluye el cronograma; si el crédito aún no tiene
        // cuotas persistidas (antes del desembolso) se proyectan las
        // tentativas, igual que CreditoController::verCronograma(). Se resuelve
        // CreditoService de forma perezosa para no crear un ciclo de
        // dependencias en el constructor.
        $cronogramaTentativo = false;

        if ($documento->tipo === 'expediente' && $credito->cuotas->isEmpty()) {
            $preview = app(CreditoService::class)->previsualizarCronograma(
                (string) $credito->monto_prestamo,
                (string) $credito->interes,
                $credito->tipo_cuota,
                $credito->numero_cuotas,
                tipoCredito: $credito->tipo_credito,
            );

            $credito->setRelation('cuotas', collect($preview['cuotas'])->map(fn (array $fila): CuotaCredito => new CuotaCredito([
                'numero_cuota' => $fila['numero_cuota'],
                'fecha_vencimiento' => $fila['fecha_vencimiento'],
                'monto_capital' => $fila['monto_capital'],
                'monto_interes' => $fila['monto_interes'],
                'monto_total' => $fila['monto_total'],
            ])));

            $cronogramaTentativo = true;
        }

        $datos = [
            'credito' => $credito,
            'documento' => $documento,
            'garantias' => $garantias,
            'datos' => $documento->datos ?? [],
            'tentativo' => $cronogramaTentativo,
            'fotoDataUri' => fn (?string $path, int $maxAncho = 900): ?string => $this->fotoDataUri($path, $maxAncho),
        ];

        if ($documento->tipo === 'saldo_deudor') {
            $datos['liquidacion'] = $this->liquidacion->liquidar($credito, now());
        }

        if ($documento->tipo === 'sticker') {
            $datos['fechaRemate'] = $this->fechaRemate($credito);
            $datos['qrDataUri'] = fn (?string $texto): ?string => $this->qrDataUri($texto);
        }

        return $this->pdfGenerator->renderizarDesdeVista($tipo->vistaDocumento($documento->tipo), $datos);
    }

    /**
     * Fecha en que el crédito, si sigue sin pagarse, queda habilitado para
     * pasar a la tienda: vencimiento + días de espera configurados (mismo
     * cálculo que CreditoService::fechaLimiteEspera(), acá solo para
     * mostrarla en el sticker). Null si el crédito aún no tiene fecha de
     * vencimiento (antes del desembolso).
     */
    private function fechaRemate(Credito $credito): ?string
    {
        if (! $credito->fecha_vencimiento) {
            return null;
        }

        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);

        return $credito->fecha_vencimiento->copy()->addDays($configuracion->dias_espera_mora)->toDateString();
    }

    /**
     * QR (PNG) del código de la garantía, como data URI embebible en un
     * <img> del PDF — mismo patrón que fotoDataUri(). Null si la garantía
     * no tiene código (dato legado antes de EsGarantia::bootEsGarantia(),
     * o una fila cargada con WithoutModelEvents sin backfill) — la vista
     * cae a un texto de reemplazo en vez de romper el sticker entero.
     */
    private function qrDataUri(?string $texto): ?string
    {
        if (! $texto) {
            return null;
        }

        $writer = new PngWriter;
        $qrCode = new QrCode(data: $texto, size: 200, margin: 4);

        return $writer->write($qrCode)->getDataUri();
    }

    /**
     * Downscales and re-encodes an uploaded foto into an embeddable data URI
     * for the "fotos" documento — real client uploads run up to 8MB each
     * (see StoreClienteRequest/StoreBienRequest), and a bien can have several,
     * so embedding them at original size would bloat the PDF into tens of MB
     * and risk exhausting memory_limit while dompdf assembles it. Shrinking
     * to 900px wide, quality 75 keeps a legible print while the resulting
     * PDF stays a reasonable size.
     */
    private function fotoDataUri(?string $path, int $maxAncho = 900): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $absoluto = Storage::disk('public')->path($path);
        $info = @getimagesize($absoluto);

        if (! $info) {
            return null;
        }

        [$ancho, $alto, $tipo] = $info;

        $origen = match ($tipo) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($absoluto),
            IMAGETYPE_PNG => imagecreatefrompng($absoluto),
            default => null,
        };

        if (! $origen) {
            return null;
        }

        if ($ancho > $maxAncho) {
            $nuevoAlto = (int) round($alto * ($maxAncho / $ancho));
            $redimensionada = imagecreatetruecolor($maxAncho, $nuevoAlto);
            imagecopyresampled($redimensionada, $origen, 0, 0, 0, 0, $maxAncho, $nuevoAlto, $ancho, $alto);
            imagedestroy($origen);
            $origen = $redimensionada;
        }

        ob_start();
        imagejpeg($origen, null, 75);
        $bytes = ob_get_clean();
        imagedestroy($origen);

        return 'data:image/jpeg;base64,'.base64_encode($bytes);
    }

    /**
     * The cronograma isn't a DocumentoCredito (no print/sign
     * lifecycle to track) — rendered straight from the crédito's cuotas,
     * same never-persisted pattern as renderizar().
     */
    public function renderizarCronograma(Credito $credito): Response
    {
        $credito->load(['cuotas', 'cliente', 'agencia', 'empresa']);

        $tipo = $this->tipos->paraCredito($credito);

        return $this->pdfGenerator->renderizarDesdeVista($tipo->vistaDocumento('cronograma'), [
            'credito' => $credito,
            'tentativo' => false,
        ]);
    }

    /**
     * Cronograma tentativo en PDF para un crédito que aún no tiene cuotas
     * persistidas (antes del desembolso): las filas proyectadas se envían a
     * la misma vista como instancias NO guardadas de CuotaCredito, así que
     * la plantilla no cambia salvo por la nota de "tentativo".
     *
     * @param  array{fecha_base: string, plazo_dias: int, cuotas: list<array{numero_cuota: int, fecha_vencimiento: string, monto_capital: string, monto_interes: string, monto_total: string}>}  $preview
     */
    public function renderizarCronogramaTentativo(Credito $credito, array $preview): Response
    {
        $credito->load(['cliente', 'agencia', 'empresa']);

        /** @var Collection<int, CuotaCredito> $cuotas */
        $cuotas = collect($preview['cuotas'])->map(fn (array $fila): CuotaCredito => new CuotaCredito([
            'numero_cuota' => $fila['numero_cuota'],
            'fecha_vencimiento' => $fila['fecha_vencimiento'],
            'monto_capital' => $fila['monto_capital'],
            'monto_interes' => $fila['monto_interes'],
            'monto_total' => $fila['monto_total'],
        ]));

        $credito->setRelation('cuotas', $cuotas);

        $tipo = $this->tipos->paraCredito($credito);

        return $this->pdfGenerator->renderizarDesdeVista($tipo->vistaDocumento('cronograma'), [
            'credito' => $credito,
            'tentativo' => true,
        ]);
    }

    public function marcarImpreso(DocumentoCredito $documento): DocumentoCredito
    {
        $documento->update(['impreso_at' => now()]);

        return $documento->fresh();
    }

    /**
     * The asesor uploads a scan/photo of the physically signed document —
     * that upload IS the confirmation of signature, no separate manual
     * toggle. Replaces any previously uploaded file for this documento.
     */
    public function subirFirmado(DocumentoCredito $documento, UploadedFile $archivo): DocumentoCredito
    {
        if ($documento->archivo_firmado_path) {
            Storage::disk('public')->delete($documento->archivo_firmado_path);
        }

        $ruta = $archivo->store("documentos-credito-prendario/{$documento->credito_id}/{$documento->id}", 'public');

        $documento->update([
            'archivo_firmado_path' => $ruta,
            'firmado_at' => now(),
        ]);

        return $documento->fresh();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function generar(Credito $credito, User $actor, string $tipo, array $datos = [], ?int $cobroId = null): DocumentoCredito
    {
        return DocumentoCredito::query()->create([
            'credito_id' => $credito->id,
            'cobro_id' => $cobroId,
            'empresa_id' => $credito->empresa_id,
            'tipo' => $tipo,
            'datos' => $datos !== [] ? $datos : null,
            'generado_por' => $actor->id,
            'generado_at' => now(),
        ]);
    }
}
