<?php

namespace App\Modules\CreditoPrendario\Services;

use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\CreditoPrendario\Models\DocumentoCreditoPrendario;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Services\PdfGeneratorService;

final class DocumentoCreditoPrendarioService
{
    public function __construct(private readonly PdfGeneratorService $pdfGenerator) {}

    public function generarContrato(CreditoPrendario $credito, User $actor): DocumentoCreditoPrendario
    {
        return $this->generar($credito, $actor, 'contrato', 'modules.credito-prendario.documentos.contrato');
    }

    public function generarDeclaracion(CreditoPrendario $credito, User $actor): DocumentoCreditoPrendario
    {
        return $this->generar($credito, $actor, 'declaracion', 'modules.credito-prendario.documentos.declaracion');
    }

    public function generarAdenda(CreditoPrendario $credito, User $actor): DocumentoCreditoPrendario
    {
        return $this->generar($credito, $actor, 'adenda', 'modules.credito-prendario.documentos.adenda');
    }

    public function marcarImpreso(DocumentoCreditoPrendario $documento): DocumentoCreditoPrendario
    {
        $documento->update(['impreso_at' => now()]);

        return $documento->fresh();
    }

    public function marcarFirmado(DocumentoCreditoPrendario $documento): DocumentoCreditoPrendario
    {
        $documento->update(['firmado_at' => now()]);

        return $documento->fresh();
    }

    private function generar(CreditoPrendario $credito, User $actor, string $tipo, string $vista): DocumentoCreditoPrendario
    {
        $credito->loadMissing(['bienes', 'cliente', 'agencia', 'empresa']);

        $ruta = "documentos-credito-prendario/{$credito->id}/{$tipo}-".now()->timestamp.'.pdf';

        $this->pdfGenerator->generarDesdeVista($vista, ['credito' => $credito], $ruta);

        return DocumentoCreditoPrendario::query()->create([
            'credito_id' => $credito->id,
            'empresa_id' => $credito->empresa_id,
            'tipo' => $tipo,
            'pdf_path' => $ruta,
            'generado_por' => $actor->id,
            'generado_at' => now(),
        ]);
    }
}
