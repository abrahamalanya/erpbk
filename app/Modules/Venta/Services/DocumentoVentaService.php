<?php

namespace App\Modules\Venta\Services;

use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\DocumentoVenta;
use App\Modules\Venta\Models\Venta;
use App\Nucleo\Services\PdfGeneratorService;
use DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Genera y renderiza los documentos de una venta. Mirror de
 * DocumentoCreditoService: nada se guarda en disco, el PDF se re-renderiza
 * en vivo desde los datos actuales de la venta en cada "ver documento".
 */
final class DocumentoVentaService
{
    /**
     * @var array<string, string>
     */
    private const VISTAS = [
        'voucher' => 'modules.venta.documentos.voucher',
        'contrato_credito' => 'modules.venta.documentos.contrato_credito',
        'contrato_apartado' => 'modules.venta.documentos.contrato_apartado',
        'compra_venta' => 'modules.venta.documentos.compra_venta',
        'notarial' => 'modules.venta.documentos.notarial',
    ];

    public function __construct(private readonly PdfGeneratorService $pdfGenerator) {}

    /**
     * Documentos que corresponden a una venta registrada (antes de quedar
     * pagada): el contrato de crédito o de apartado, según la modalidad. El
     * contado no genera nada aquí — su único documento es el voucher que
     * emite generarDocumentosFinales() al quedar pagado de inmediato.
     */
    public function generarContratoInicial(Venta $venta, User $actor): ?DocumentoVenta
    {
        $tipo = match ($venta->forma_venta) {
            'credito' => 'contrato_credito',
            'apartado' => 'contrato_apartado',
            default => null,
        };

        return $tipo === null ? null : $this->generar($venta, $tipo, $actor);
    }

    /**
     * Documentos que corresponden a una venta que queda pagada en su
     * totalidad: siempre un voucher de pago, y además el documento legal de
     * transferencia según el tipo de artículo — "compra y venta" para
     * vehículos, notarial para inmuebles. Los bienes (prendario) solo
     * llevan voucher.
     *
     * @return list<DocumentoVenta>
     */
    public function generarDocumentosFinales(Venta $venta): array
    {
        $documentos = [$this->generar($venta, 'voucher')];

        $extra = match ($venta->articulo_type) {
            'vehiculo' => 'compra_venta',
            'inmueble' => 'notarial',
            default => null,
        };

        if ($extra !== null) {
            $documentos[] = $this->generar($venta, $extra);
        }

        return $documentos;
    }

    public function generar(Venta $venta, string $tipo, ?User $actor = null): DocumentoVenta
    {
        if (! isset(self::VISTAS[$tipo])) {
            throw new DomainException("Tipo de documento de venta desconocido: {$tipo}");
        }

        return DocumentoVenta::query()->create([
            'venta_id' => $venta->id,
            'empresa_id' => $venta->empresa_id,
            'tipo' => $tipo,
            'generado_por' => $actor?->id,
            'generado_at' => now(),
        ]);
    }

    /**
     * El cronograma no es un DocumentoVenta (no tiene ciclo de vida de
     * impresión/firma) — se renderiza directo desde las cuotas ya
     * persistidas de la venta, mismo patrón nunca-guardado que renderizar().
     * Solo aplica a forma_venta = credito (VentaController::verCronograma()
     * ya lo valida antes de llamar aquí).
     */
    public function renderizarCronograma(Venta $venta): Response
    {
        $venta->load(['cuotas', 'cliente', 'articulo', 'empresa']);

        return $this->pdfGenerator->renderizarDesdeVista('modules.venta.documentos.cronograma', [
            'venta' => $venta,
        ]);
    }

    public function renderizar(DocumentoVenta $documento): Response
    {
        $vista = self::VISTAS[$documento->tipo] ?? throw new DomainException("Tipo de documento de venta desconocido: {$documento->tipo}");

        $venta = $documento->venta()
            ->with(['cliente', 'vendidoPor', 'articulo.fotos', 'empresa', 'agencia', 'cuotas', 'pagos'])
            ->firstOrFail();

        return $this->pdfGenerator->renderizarDesdeVista($vista, [
            'venta' => $venta,
            'documento' => $documento,
            'articulo' => $venta->articulo,
        ]);
    }
}
