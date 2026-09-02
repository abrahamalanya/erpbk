<?php

namespace App\Modules\Credito\Services;

use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\DocumentoCredito;
use App\Modules\Credito\Tipos\CreditoTipoManager;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Services\PdfGeneratorService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class DocumentoCreditoService
{
    /**
     * @var list<string>
     */
    private const TIPOS_DOCUMENTO = ['contrato', 'declaracion', 'adenda', 'fotos', 'devolucion'];

    public function __construct(
        private readonly PdfGeneratorService $pdfGenerator,
        private readonly CreditoTipoManager $tipos,
    ) {}

    public function generarContrato(Credito $credito, User $actor): DocumentoCredito
    {
        return $this->generar($credito, $actor, 'contrato');
    }

    public function generarDeclaracion(Credito $credito, User $actor): DocumentoCredito
    {
        return $this->generar($credito, $actor, 'declaracion');
    }

    public function generarAdenda(Credito $credito, User $actor): DocumentoCredito
    {
        return $this->generar($credito, $actor, 'adenda');
    }

    /**
     * The "constancia fotográfica" — the client's photo with each bien plus
     * the bien's own product photos, printed for the client to sign
     * alongside the contrato/declaración (same firma_at gate before
     * desembolsar).
     */
    public function generarFotos(Credito $credito, User $actor): DocumentoCredito
    {
        return $this->generar($credito, $actor, 'fotos');
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
     * Renders the PDF fresh from the crédito's current data — nothing is
     * kept on disk, so this runs again on every "ver documento" request.
     */
    public function renderizar(DocumentoCredito $documento): Response
    {
        if (! in_array($documento->tipo, self::TIPOS_DOCUMENTO, true)) {
            throw new DomainException("Tipo de documento desconocido: {$documento->tipo}");
        }

        $credito = $documento->credito()->with(['cliente', 'agencia', 'empresa'])->firstOrFail();

        $tipo = $this->tipos->paraCredito($credito);
        $garantias = $credito->garantiasComo($tipo->garantiaModelo())->with('fotos')->get();

        $datos = [
            'credito' => $credito,
            'documento' => $documento,
            'garantias' => $garantias,
            'fotoDataUri' => fn (?string $path, int $maxAncho = 900): ?string => $this->fotoDataUri($path, $maxAncho),
        ];

        return $this->pdfGenerator->renderizarDesdeVista($tipo->vistaDocumento($documento->tipo), $datos);
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

        return $this->pdfGenerator->renderizarDesdeVista($tipo->vistaDocumento('cronograma'), ['credito' => $credito]);
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

    private function generar(Credito $credito, User $actor, string $tipo): DocumentoCredito
    {
        return DocumentoCredito::query()->create([
            'credito_id' => $credito->id,
            'empresa_id' => $credito->empresa_id,
            'tipo' => $tipo,
            'generado_por' => $actor->id,
            'generado_at' => now(),
        ]);
    }
}
