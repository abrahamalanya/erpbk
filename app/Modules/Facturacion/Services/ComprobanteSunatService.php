<?php

namespace App\Modules\Facturacion\Services;

use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ComprobanteDetalle;
use App\Modules\Facturacion\Models\CredencialSunat;
use App\Modules\Facturacion\Services\Sunat\CdrParser;
use App\Modules\Facturacion\Services\Sunat\SunatBajaClient;
use App\Modules\Facturacion\Services\Sunat\SunatClientInterface;
use App\Modules\Facturacion\Services\Sunat\SunatSoapClient;
use App\Modules\Facturacion\Services\Sunat\UblBuilder;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Orquestador de la emisión SUNAT: XML → Firma → ZIP → Envío → CDR.
 *
 * Este servicio une UblBuilder, SunatClientInterface y CdrParser.
 * Es la capa que el Job llama, y que los tests pueden sustituir con fakes.
 */
final class ComprobanteSunatService
{
    public function __construct(
        private readonly UblBuilder $ublBuilder,
        private readonly CdrParser $cdrParser,
    ) {}

    /**
     * Genera XML firmado, ZIP y envía a SUNAT.
     *
     * @return array{
     *     exito: bool,
     *     xml: string,
     *     zip: string,
     *     cdr_codigo: ?string,
     *     cdr_descripcion: ?string,
     *     cdr_xml: ?string,
     *     cdr_digests: ?array,
     *     hash_cdr: ?string,
     *     codigo_seguridad: ?string,
     *     error: ?string
     * }
     */
    public function emitir(Comprobante $comprobante, CredencialSunat $credencial, string $modo): array
    {
        // 1. Generar XML firmado y ZIP
        $xml = $this->ublBuilder->build($comprobante);
        $zip = $this->ublBuilder->buildZip($comprobante);

        // 2. Guardar XML y ZIP en disco privado (auditoría)
        $xmlPath = $this->guardarArchivo($comprobante, 'xml', $xml);
        $zipPath = $this->guardarArchivo($comprobante, 'zip', $zip);

        // 3. Crear cliente SUNAT y enviar
        $client = new SunatSoapClient($credencial, $modo);
        $respuesta = $client->enviarComprobante($comprobante, $xml, $zipPath);

        // 4. Procesar CDR si hay respuesta
        if ($respuesta['cdr_xml']) {
            // El CDR ya viene parseado en el cliente, solo extraer código de seguridad
            $codigoSeguridad = $this->cdrParser->extraerCodigoSeguridadXml($respuesta['cdr_xml']);
            $respuesta['codigo_seguridad'] = $codigoSeguridad ?? $respuesta['codigo_seguridad'];
            $parsed = $this->cdrParser->parseXml($respuesta['cdr_xml']);
            $respuesta['hash_cdr'] = $respuesta['hash_cdr'] ?? $parsed['digest'];
        }

        // 5. Agregar rutas de archivos a la respuesta
        $respuesta['xml_path'] = $xmlPath;
        $respuesta['zip_path'] = $zipPath;
        $respuesta['xml'] = $xml;
        $respuesta['zip'] = $zip;

        return $respuesta;
    }

    /**
     * Genera XML y ZIP SIN enviar a SUNAT (para preview, tests, homologación).
     */
    public function generarArchivos(Comprobante $comprobante): array
    {
        $xml = $this->ublBuilder->build($comprobante);
        $zip = $this->ublBuilder->buildZip($comprobante);

        return [
            'xml' => $xml,
            'zip' => $zip,
            'xml_path' => $this->guardarArchivo($comprobante, 'xml', $xml),
            'zip_path' => $this->guardarArchivo($comprobante, 'zip', $zip),
        ];
    }

    /**
     * Genera XML SIN firmar (para validación XSD en tests).
     */
    public function generarXmlSinFirmar(Comprobante $comprobante): string
    {
        return $this->ublBuilder->buildSinFirmar($comprobante);
    }

    /**
     * Consulta el estado de un comprobante ya enviado.
     */
    public function consultarEstado(Comprobante $comprobante, CredencialSunat $credencial, string $modo): array
    {
        $client = new SunatSoapClient($credencial, $modo);
        return $client->consultarEstado($comprobante);
    }

    /**
     * Envía resumen diario de boletas.
     */
    public function enviarResumenDiario(array $comprobantes, CredencialSunat $credencial, string $modo): array
    {
        // Generar ZIP del resumen (el ZIP real se genera en el cliente SOAP)
        // Aquí solo orquestamos
        $client = new SunatSoapClient($credencial, $modo);

        // El ZIP del resumen se genera dentro del cliente, pasamos uno vacío
        // porque Greenter lo construye internamente con el Report
        $zipTemporal = $this->generarZipResumenVacio($comprobantes);

        return $client->enviarResumenDiario($comprobantes, $zipTemporal);
    }

    /**
     * Consulta el CDR de un resumen diario enviado.
     */
    public function consultarResumenDiario(string $ticket, CredencialSunat $credencial, string $modo): array
    {
        $client = new SunatSoapClient($credencial, $modo);
        return $client->consultarResumenDiario($ticket);
    }

    /**
     * Verifica cupo de documentos disponibles.
     */
    public function verificarCupo(CredencialSunat $credencial, string $modo): array
    {
        $client = new SunatSoapClient($credencial, $modo);
        return $client->verificarCupo();
    }

    /**
     * Actualiza el comprobante con la respuesta de SUNAT.
     */
    public function aplicarRespuesta(Comprobante $comprobante, array $respuesta): Comprobante
    {
        $comprobante->update([
            'estado' => $this->mapearEstado($respuesta),
            'cdr_codigo' => $respuesta['cdr_codigo'] ?? null,
            'cdr_descripcion' => $respuesta['cdr_descripcion'] ?? null,
            'hash_cdr' => $respuesta['hash_cdr'] ?? null,
            'cdr_digests' => $respuesta['cdr_digests'] ?? null,
            'codigo_seguridad' => $respuesta['codigo_seguridad'] ?? null,
            'xml_path' => $respuesta['xml_path'] ?? $comprobante->xml_path,
            'cdr_path' => $this->guardarCdr($comprobante, $respuesta['cdr_xml'] ?? ''),
            'zip_path' => $respuesta['zip_path'] ?? $comprobante->zip_path,
            'enviado_at' => now(),
            'aceptado_at' => $respuesta['exito'] && ($respuesta['cdr_codigo'] === '0' || $respuesta['cdr_codigo'] === null) ? now() : null,
        ]);

        return $comprobante->fresh();
    }

    /**
     * Marca el comprobante como error técnico (reintentable).
     */
    public function marcarError(Comprobante $comprobante, string $error): Comprobante
    {
        $comprobante->update([
            'estado' => EstadoComprobante::Error->value,
            'cdr_descripcion' => $error,
            'enviado_at' => now(),
        ]);

        return $comprobante->fresh();
    }

    private function mapearEstado(array $respuesta): string
    {
        if (! $respuesta['exito']) {
            if (str_starts_with((string) $respuesta['cdr_codigo'], '4')) {
                return EstadoComprobante::Rechazado->value;
            }
            if (str_starts_with((string) $respuesta['cdr_codigo'], '2')) {
                return EstadoComprobante::Observado->value;
            }
            return EstadoComprobante::Error->value;
        }

        if (str_starts_with((string) $respuesta['cdr_codigo'], '2')) {
            return EstadoComprobante::Observado->value;
        }

        return EstadoComprobante::Aceptado->value;
    }

    private function guardarArchivo(Comprobante $comprobante, string $extension, string $contenido): string
    {
        $carpeta = "comprobantes/{$comprobante->empresa_id}/{$comprobante->fecha_emision->format('Y/m')}";
        $filename = "{$comprobante->numero}.{$extension}";

        Storage::disk('privado')->put("{$carpeta}/{$filename}", $contenido);

        return "{$carpeta}/{$filename}";
    }

    private function guardarCdr(Comprobante $comprobante, string $cdrXml): ?string
    {
        if (! $cdrXml) {
            return null;
        }

        $carpeta = "comprobantes/{$comprobante->empresa_id}/{$comprobante->fecha_emision->format('Y/m')}/cdr";
        $filename = "{$comprobante->numero}-cdr.xml";

        Storage::disk('privado')->put("{$carpeta}/{$filename}", $cdrXml);

        return "{$carpeta}/{$filename}";
    }

    private function generarZipResumenDiario(array $comprobantes): string
    {
        // Greenter construye el ZIP internamente en sendSummary,
        // así que solo necesitamos un placeholder
        return '';
    }

    private function generarZipResumenVacio(array $comprobantes): string
    {
        // Placeholder - el cliente SOAP genera el ZIP real
        return '';
    }

    /**
     * Comunica la baja de un comprobante ya aceptado/observado.
     *
     * @return array{
     *     exito: bool,
     *     ticket: ?string,
     *     cdr_codigo: ?string,
     *     cdr_descripcion: ?string,
     *     cdr_xml: ?string,
     *     error: ?string
     * }
     */
    public function comunicarBaja(Comprobante $comprobante, CredencialSunat $credencial, string $modo, string $motivo): array
    {
        $client = new SunatBajaClient($credencial, $modo);
        return $client->comunicarBaja($comprobante, $motivo);
    }

    /**
     * Aplica la respuesta de comunicación de baja al comprobante.
     */
    public function aplicarRespuestaBaja(Comprobante $comprobante, array $respuesta): Comprobante
    {
        if ($respuesta['exito'] && $respuesta['cdr_codigo'] === '0') {
            // Baja aceptada directamente
            $comprobante->update([
                'estado' => EstadoComprobante::Anulado->value,
                'baja_comunicada_at' => now(),
                'motivo_anulacion' => $comprobante->motivo_anulacion,
                'cdr_codigo' => $respuesta['cdr_codigo'],
                'cdr_descripcion' => $respuesta['cdr_descripcion'],
                'cdr_path' => $this->guardarCdr($comprobante, $respuesta['cdr_xml'] ?? ''),
            ]);
        } elseif ($respuesta['exito'] && $respuesta['ticket']) {
            // Baja enviada, CDR asíncrono (ticket)
            $comprobante->update([
                'estado' => EstadoComprobante::Enviado->value, // Pendiente de CDR
                'motivo_anulacion' => $comprobante->motivo_anulacion,
                'resumen_diario_ticket' => $respuesta['ticket'],
                'resumen_diario_enviado_at' => now(),
            ]);
        } else {
            // Error
            $comprobante->update([
                'estado' => EstadoComprobante::Error->value,
                'cdr_descripcion' => $respuesta['error'] ?? 'Error comunicando baja',
                'enviado_at' => now(),
            ]);
        }

        return $comprobante->fresh();
    }

    /**
     * Consulta el CDR de una comunicación de baja o resumen diario.
     */
    public function consultarCdrBajaResumen(string $ticket, CredencialSunat $credencial, string $modo): array
    {
        $client = new SunatBajaClient($credencial, $modo);
        return $client->consultarCdrResumen($ticket);
    }

    /**
     * Emite una nota de crédito (tipo 07) referenciando un comprobante origen.
     *
     * @param array<int, array{descripcion: string, cantidad: float, valor_unitario: float, total: float, igv: float, categoria: string}> $lineas
     * @return Comprobante La nota de crédito creada (estado: generado)
     */
    public function emitirNotaCredito(
        Comprobante $comprobanteOrigen,
        CredencialSunat $credencial,
        string $modo,
        string $motivo,
        array $lineas,
        \App\Modules\Usuario\Models\User $actor
    ): Comprobante {
        // Validar que el origen está aceptado/observado
        if (! in_array($comprobanteOrigen->estado->value, ['aceptado', 'observado'], true)) {
            throw new DomainException('Solo se puede emitir nota de crédito sobre comprobantes aceptados u observados');
        }

        // Calcular totales
        $subtotal = '0.00';
        $igv = '0.00';
        $total = '0.00';

        foreach ($lineas as $linea) {
            $subtotal = bcadd($subtotal, (string) $linea['valor_venta'] ?? $linea['valor_unitario'], 2);
            $igv = bcadd($igv, (string) $linea['igv'], 2);
            $total = bcadd($total, (string) $linea['total'], 2);
        }

        // Obtener siguiente correlativo para nota de crédito (serie BC01, BC02, etc.)
        $serieNc = $this->obtenerSerieNotaCredito($comprobanteOrigen->empresa_id, $comprobanteOrigen->agencia_id);
        $correlativo = $this->siguienteCorrelativoNotaCredito($serieNc);

        $numero = $serieNc->serie.'-'.str_pad((string) $correlativo, 8, '0', STR_PAD_LEFT);

        // Crear la nota de crédito
        $notaCredito = Comprobante::create([
            'empresa_id' => $comprobanteOrigen->empresa_id,
            'agencia_id' => $comprobanteOrigen->agencia_id,
            'cliente_id' => $comprobanteOrigen->cliente_id,
            'origen_tipo' => 'nota_credito',
            'origen_id' => $comprobanteOrigen->id,
            'series_comprobante_id' => $serieNc->id,
            'tipo_documento' => TipoComprobante::NotaCredito->value,
            'serie' => $serieNc->serie,
            'correlativo' => $correlativo,
            'numero' => $numero,
            'fecha_emision' => now()->toDateString(),
            'hora_emision' => now()->toTimeString(),
            'moneda' => $comprobanteOrigen->moneda,
            'subtotal' => $subtotal,
            'igv' => $igv,
            'total' => $total,
            'importe_letras' => \App\Nucleo\Support\NumeroALetras::soles($total),
            'porcentaje_igv' => $comprobanteOrigen->porcentaje_igv,
            'forma_pago' => $comprobanteOrigen->forma_pago,
            'medio_pago' => $comprobanteOrigen->medio_pago,
            'estado' => EstadoComprobante::Generado->value,
            'observaciones' => $motivo,
            'documento_origen_id' => $comprobanteOrigen->id,
            'emisor_ruc' => $comprobanteOrigen->emisor_ruc,
            'emisor_razon_social' => $comprobanteOrigen->emisor_razon_social,
            'emisor_nombre_comercial' => $comprobanteOrigen->emisor_nombre_comercial,
            'emisor_domicilio' => $comprobanteOrigen->emisor_domicilio,
            'emisor_ubigeo' => $comprobanteOrigen->emisor_ubigeo,
            'emisor_direccion_completa' => $comprobanteOrigen->emisor_direccion_completa,
            'receptor_tipo_documento' => $comprobanteOrigen->receptor_tipo_documento,
            'receptor_numero_documento' => $comprobanteOrigen->receptor_numero_documento,
            'receptor_nombre' => $comprobanteOrigen->receptor_nombre,
            'receptor_domicilio' => $comprobanteOrigen->receptor_domicilio,
            'receptor_ubigeo' => $comprobanteOrigen->receptor_ubigeo,
            'receptor_email' => $comprobanteOrigen->receptor_email,
            'generado_por' => $actor->id,
        ]);

        // Crear líneas de detalle
        foreach ($lineas as $i => $linea) {
            ComprobanteDetalle::create([
                'comprobante_id' => $notaCredito->id,
                'empresa_id' => $notaCredito->empresa_id,
                'orden' => $i + 1,
                'descripcion' => $linea['descripcion'],
                'unidad_medida' => $linea['unidad_medida'] ?? 'ZZ',
                'cantidad' => (string) ($linea['cantidad'] ?? 1),
                'valor_unitario' => (string) $linea['valor_unitario'],
                'precio_unitario' => bcadd((string) $linea['valor_unitario'], (string) $linea['igv'], 2),
                'descuento' => '0.00',
                'valor_venta' => (string) ($linea['valor_venta'] ?? $linea['valor_unitario']),
                'igv' => (string) $linea['igv'],
                'total' => (string) $linea['total'],
                'categoria_tributo' => $linea['categoria'] ?? 'S',
                'porcentaje_igv' => $comprobanteOrigen->porcentaje_igv,
            ]);
        }

        // Generar XML y ZIP y enviar
        $xml = $this->ublBuilder->build($notaCredito);
        $zip = $this->ublBuilder->buildZip($notaCredito);

        $xmlPath = $this->guardarArchivo($notaCredito, 'xml', $xml);
        $zipPath = $this->guardarArchivo($notaCredito, 'zip', $zip);

        $client = new SunatSoapClient($credencial, $modo);
        $respuesta = $client->enviarComprobante($notaCredito, $xml, $zipPath);

        if ($respuesta['cdr_xml']) {
            $codigoSeguridad = $this->cdrParser->extraerCodigoSeguridadXml($respuesta['cdr_xml']);
            $respuesta['codigo_seguridad'] = $codigoSeguridad ?? $respuesta['codigo_seguridad'];
            $parsed = $this->cdrParser->parseXml($respuesta['cdr_xml']);
            $respuesta['hash_cdr'] = $respuesta['hash_cdr'] ?? $parsed['digest'];
        }

        $respuesta['xml_path'] = $xmlPath;
        $respuesta['zip_path'] = $zipPath;

        $this->aplicarRespuesta($notaCredito, $respuesta);

        // Actualizar origen a "sustituido" si la NC fue aceptada
        if ($notaCredito->fresh()->esValido()) {
            $comprobanteOrigen->update([
                'estado' => EstadoComprobante::Sustituido->value,
            ]);
        }

        return $notaCredito->fresh(['detalles', 'serieComprobante']);
    }

    /**
     * Obtiene la serie para notas de crédito (BC01, BC02, etc.).
     */
    private function obtenerSerieNotaCredito(int $empresaId, ?int $agenciaId): \App\Modules\Facturacion\Models\SerieComprobante
    {
        $serie = \App\Modules\Facturacion\Models\SerieComprobante::query()
            ->where('empresa_id', $empresaId)
            ->where('agencia_id', $agenciaId)
            ->where('tipo_documento', '07')
            ->where('estado', true)
            ->first();

        if (! $serie) {
            // Fallback a casa matriz
            $serie = \App\Modules\Facturacion\Models\SerieComprobante::query()
                ->where('empresa_id', $empresaId)
                ->whereNull('agencia_id')
                ->where('tipo_documento', '07')
                ->where('estado', true)
                ->first();
        }

        if (! $serie) {
            throw new DomainException('No hay serie de nota de crédito (07) activa para esta empresa/agencia. Registre una en Facturación → Series.');
        }

        return $serie;
    }

    /**
     * Reserva el siguiente correlativo para nota de crédito.
     */
    private function siguienteCorrelativoNotaCredito(\App\Modules\Facturacion\Models\SerieComprobante $serie): int
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($serie): int {
            $bloqueada = \App\Modules\Facturacion\Models\SerieComprobante::query()
                ->whereKey($serie->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $bloqueada->tieneCupo()) {
                throw new DomainException("La serie {$bloqueada->serie} alcanzó el límite de numeración.");
            }

            $siguiente = $bloqueada->ultimo_correlativo + 1;
            $bloqueada->update(['ultimo_correlativo' => $siguiente]);

            return $siguiente;
        });
    }
}