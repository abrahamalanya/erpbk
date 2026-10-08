<?php

namespace App\Modules\Facturacion\Services;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\Credito;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Facturacion\Models\ComprobanteDetalle;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Support\DocumentoIdentidad;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Emisión y ciclo de vida del comprobante.
 *
 * El flujo completo de una emisión es:
 *
 *   1. validar que el crédito está cancelado y sin comprobante previo
 *   2. validar que el emisor tiene sus datos fiscales completos
 *   3. decidir factura o boleta según el documento del receptor
 *   4. calcular la base imponible desde los cobros del crédito
 *   5. reservar el correlativo (transaccional, sin huecos)
 *   6. insertar el comprobante con el snapshot fiscal
 *   7. insertar las líneas del detalle
 *
 * Todo va en una transacción: si algo falla a la mitad, no queda un
 * comprobante a medio armar ni un correlativo consumido sin documento.
 */
final class ComprobanteService
{
    public function __construct(
        private readonly BaseImponibleService $baseImponible,
        private readonly SerieService $series,
        private readonly ConfiguracionFacturacionService $configuracion,
    ) {}

    /**
     * Emite el comprobante de un crédito cancelado.
     *
     * @return Comprobante el comprobante ya en estado `generado`
     */
    public function emitirDesdeCredito(Credito $credito, User $actor): Comprobante
    {
        $this->asegurarCreditoFacturable($credito);
        $this->asegurarSinComprobantePrevio($credito);

        $configuracion = $this->configuracion->resolverPara($credito->empresa);
        $emisor = $this->datosEmisor($credito->empresa);
        $receptor = $this->datosReceptor($credito->cliente);

        $tipo = $this->tipoComprobantePara($receptor['tipo_documento']);
        $serie = $this->series->serieParaEmitir($credito->empresa_id, $credito->agencia_id, $tipo);

        $desglose = $this->baseImponible->desdeCredito($credito, $configuracion);

        return DB::transaction(function () use (
            $credito, $actor, $serie, $tipo, $emisor, $receptor, $desglose,
        ): Comprobante {
            $correlativo = $this->series->siguienteCorrelativo($serie);

            $comprobante = Comprobante::query()->create([
                'empresa_id' => $credito->empresa_id,
                'agencia_id' => $credito->agencia_id,
                'cliente_id' => $credito->cliente_id,
                'origen_tipo' => 'credito',
                'origen_id' => $credito->id,
                'series_comprobante_id' => $serie->id,
                'tipo_documento' => $tipo->value,
                'serie' => $serie->serie,
                'correlativo' => $correlativo,
                'numero' => $this->series->formatearNumero($serie->serie, $correlativo),
                'fecha_emision' => now()->toDateString(),
                'hora_emision' => now()->toTimeString(),
                'moneda' => 'PEN',
                'subtotal' => $desglose['base'],
                'igv' => $desglose['igv'],
                'total' => $desglose['total'],
                'importe_letras' => $desglose['total_letras'],
                'porcentaje_igv' => $desglose['porcentaje_igv'],
                'forma_pago' => '01',
                'estado' => EstadoComprobante::Generado->value,
                'emisor_ruc' => $emisor['ruc'],
                'emisor_razon_social' => $emisor['razon_social'],
                'emisor_nombre_comercial' => $emisor['nombre_comercial'],
                'emisor_domicilio' => $emisor['direccion'],
                'emisor_ubigeo' => $emisor['ubigeo'],
                'emisor_direccion_completa' => $emisor['direccion_completa'],
                'receptor_tipo_documento' => $receptor['tipo_documento'],
                'receptor_numero_documento' => $receptor['numero_documento'],
                'receptor_nombre' => $receptor['nombre'],
                'receptor_domicilio' => $receptor['direccion'],
                'receptor_ubigeo' => $receptor['ubigeo'],
                'receptor_email' => $receptor['email'],
                'generado_por' => $actor->id,
            ]);

            foreach ($desglose['lineas'] as $linea) {
                ComprobanteDetalle::query()->create([
                    'comprobante_id' => $comprobante->id,
                    'empresa_id' => $comprobante->empresa_id,
                    'orden' => $linea['orden'],
                    'descripcion' => $linea['descripcion'],
                    'unidad_medida' => $linea['unidad_medida'],
                    'cantidad' => $linea['cantidad'],
                    'valor_unitario' => $linea['valor_unitario'],
                    'precio_unitario' => $linea['precio_unitario'],
                    'descuento' => $linea['descuento'],
                    'valor_venta' => $linea['valor_venta'],
                    'igv' => $linea['igv'],
                    'total' => $linea['total'],
                    'categoria_tributo' => $linea['categoria_tributo'],
                    'porcentaje_igv' => $linea['porcentaje_igv'],
                    'cobro_id' => $linea['cobro_id'],
                ]);
            }

            return $comprobante->fresh(['detalles', 'serieComprobante']);
        });
    }

    /**
     * Créditos cancelados que todavía no tienen comprobante. Es la pantalla
     * que pidió el cliente en APUNTES.md: "seleccionamos entre los créditos
     * cancelados".
     *
     * @return Collection<int, Credito>
     */
    public function pendientesDeFacturar(int $empresaId, ?int $agenciaId = null): Collection
    {
        return Credito::query()
            ->where('empresa_id', $empresaId)
            ->whereIn('estado', ['liquidado', 'liquidado_pendiente'])
            ->whereDoesntHave('comprobantes', fn ($query) => $query->where('origen_tipo', 'credito'))
            ->when($agenciaId !== null, fn ($query) => $query->where('agencia_id', $agenciaId))
            ->with(['cliente', 'cobros' => fn ($query) => $query->where('estado', 'registrado')])
            ->orderBy('fecha_vencimiento')
            ->get();
    }

    /**
     * Vista previa de lo que se va a facturar, sin consumir correlativo.
     *
     * @return array{credito: int, tipo: string, serie: string, base: string, igv: string, total: string, lineas: int}
     */
    public function previsualizar(Credito $credito): array
    {
        $this->asegurarCreditoFacturable($credito);

        $configuracion = $this->configuracion->resolverPara($credito->empresa);
        $receptor = $this->datosReceptor($credito->cliente);
        $tipo = $this->tipoComprobantePara($receptor['tipo_documento']);
        $serie = $this->series->serieParaEmitir($credito->empresa_id, $credito->agencia_id, $tipo);
        $desglose = $this->baseImponible->desdeCredito($credito, $configuracion);

        return [
            'credito' => $credito->id,
            'codigo' => $credito->codigo,
            'cliente' => $receptor['nombre'],
            'tipo' => $tipo->value,
            'tipo_nombre' => $tipo->denominacion(),
            'serie' => $serie->serie,
            'correlativo_siguiente' => $serie->ultimo_correlativo + 1,
            'base' => $desglose['base'],
            'igv' => $desglose['igv'],
            'total' => $desglose['total'],
            'lineas' => count($desglose['lineas']),
        ];
    }

    /**
     * Anula un comprobante que todavía no se comunicó a SUNAT. Los que ya
     * fueron aceptados no se pueden anular así: exigen comunicación de baja
     * o nota de crédito (EstadoComprobante::anulableLocalmente()).
     */
    public function anular(Comprobante $comprobante, User $actor, string $motivo): Comprobante
    {
        if (! $comprobante->estado->anulableLocalmente()) {
            throw new DomainException(
                'Este comprobante ya fue aceptado por SUNAT: no se puede anular directamente. Debe emitir una nota de crédito o comunicar la baja.'
            );
        }

        $comprobante->update([
            'estado' => EstadoComprobante::Anulado->value,
            'anulado_por' => $actor->id,
            'anulado_at' => now(),
            'motivo_anulacion' => $motivo,
        ]);

        return $comprobante->fresh();
    }

    /**
     * @throws DomainException si el crédito no está cancelado o ya tiene comprobante
     */
    private function asegurarCreditoFacturable(Credito $credito): void
    {
        if (! in_array($credito->estado, ['liquidado', 'liquidado_pendiente'], true)) {
            throw new DomainException(
                "El crédito {$credito->codigo} no está cancelado (estado: {$credito->estado}). Solo se factura un crédito liquidado."
            );
        }
    }

    private function asegurarSinComprobantePrevio(Credito $credito): void
    {
        $existe = Comprobante::query()
            ->where('origen_tipo', 'credito')
            ->where('origen_id', $credito->id)
            ->where('estado', '!=', EstadoComprobante::Anulado->value)
            ->exists();

        if ($existe) {
            throw new DomainException("El crédito {$credito->codigo} ya tiene un comprobante emitido.");
        }
    }

    /**
     * Snapshot del emisor. Si falta algo, se dice exactamente qué, porque el
     * comprobante no puede salir a medias.
     *
     * @return array{ruc: string, razon_social: string, nombre_comercial: ?string, direccion: ?string, ubigeo: ?string, direccion_completa: ?string}
     */
    private function datosEmisor(Empresa $empresa): array
    {
        $faltantes = $empresa->datosFiscalesFaltantes();

        if ($faltantes !== []) {
            throw new DomainException(
                'La empresa no tiene completos sus datos fiscales: '.implode(', ', $faltantes).'. Complete la configuración antes de emitir.'
            );
        }

        $datos = $empresa->datosFiscales();

        return [
            'ruc' => $empresa->ruc,
            'razon_social' => $empresa->razon_social,
            'nombre_comercial' => $empresa->nombre,
            'direccion' => $datos['direccion'],
            'ubigeo' => $datos['ubigeo'],
            'direccion_completa' => $datos['direccion'],
        ];
    }

    /**
     * Snapshot del receptor. Para una persona jurídica la factura va a nombre
     * de la empresa; para una persona natural, a su nombre.
     *
     * @return array{tipo_documento: string, numero_documento: string, nombre: string, direccion: ?string, ubigeo: ?string, email: ?string}
     */
    private function datosReceptor(Cliente $cliente): array
    {
        $nombre = $cliente->tipo_documento === 'ruc'
            ? ($cliente->nombre ?: $cliente->apellido)
            : trim($cliente->nombre.' '.$cliente->apellido);

        return [
            'tipo_documento' => $cliente->tipo_documento,
            'numero_documento' => $cliente->numero_documento,
            'nombre' => $nombre,
            'direccion' => $cliente->direccion,
            'ubigeo' => $cliente->ubigeoDistrito?->ubigeo(),
            'email' => $cliente->email,
        ];
    }

    /**
     * Factura si el receptor tiene RUC, boleta si no. Es la regla de SUNAT:
     * una factura sin RUC de receptor es inválida.
     */
    private function tipoComprobantePara(string $tipoDocumento): TipoComprobante
    {
        return DocumentoIdentidad::comprobantePara($tipoDocumento) === 'factura'
            ? TipoComprobante::Factura
            : TipoComprobante::Boleta;
    }
}
