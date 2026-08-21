<?php

namespace App\Modules\CreditoPrendario\Services;

use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\CreditoPrendario\Models\DocumentoCreditoPrendario;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Services\PdfGeneratorService;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class DocumentoCreditoPrendarioService
{
    /**
     * @var array<string, string>
     */
    private const VISTAS = [
        'contrato' => 'modules.credito-prendario.documentos.contrato',
        'declaracion' => 'modules.credito-prendario.documentos.declaracion',
        'adenda' => 'modules.credito-prendario.documentos.adenda',
    ];

    public function __construct(private readonly PdfGeneratorService $pdfGenerator) {}

    public function generarContrato(CreditoPrendario $credito, User $actor): DocumentoCreditoPrendario
    {
        return $this->generar($credito, $actor, 'contrato');
    }

    public function generarDeclaracion(CreditoPrendario $credito, User $actor): DocumentoCreditoPrendario
    {
        return $this->generar($credito, $actor, 'declaracion');
    }

    public function generarAdenda(CreditoPrendario $credito, User $actor): DocumentoCreditoPrendario
    {
        return $this->generar($credito, $actor, 'adenda');
    }

    /**
     * Renders the PDF fresh from the crédito's current data — nothing is
     * kept on disk, so this runs again on every "ver documento" request.
     */
    public function renderizar(DocumentoCreditoPrendario $documento): Response
    {
        $vista = self::VISTAS[$documento->tipo] ?? throw new DomainException("Tipo de documento desconocido: {$documento->tipo}");

        $credito = $documento->credito()->with(['bienes', 'cliente', 'agencia', 'empresa'])->firstOrFail();

        return $this->pdfGenerator->renderizarDesdeVista($vista, ['credito' => $credito]);
    }

    /**
     * The cronograma isn't a DocumentoCreditoPrendario (no print/sign
     * lifecycle to track) — rendered straight from the crédito's cuotas,
     * same never-persisted pattern as renderizar().
     */
    public function renderizarCronograma(CreditoPrendario $credito): Response
    {
        $credito->load(['cuotas', 'cliente', 'agencia', 'empresa']);

        return $this->pdfGenerator->renderizarDesdeVista('modules.credito-prendario.documentos.cronograma', ['credito' => $credito]);
    }

    public function marcarImpreso(DocumentoCreditoPrendario $documento): DocumentoCreditoPrendario
    {
        $documento->update(['impreso_at' => now()]);

        return $documento->fresh();
    }

    /**
     * The asesor uploads a scan/photo of the physically signed document —
     * that upload IS the confirmation of signature, no separate manual
     * toggle. Replaces any previously uploaded file for this documento.
     */
    public function subirFirmado(DocumentoCreditoPrendario $documento, UploadedFile $archivo): DocumentoCreditoPrendario
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

    private function generar(CreditoPrendario $credito, User $actor, string $tipo): DocumentoCreditoPrendario
    {
        return DocumentoCreditoPrendario::query()->create([
            'credito_id' => $credito->id,
            'empresa_id' => $credito->empresa_id,
            'tipo' => $tipo,
            'generado_por' => $actor->id,
            'generado_at' => now(),
        ]);
    }
}
