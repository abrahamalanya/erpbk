<?php

namespace App\Modules\Facturacion\Services;

use App\Modules\Facturacion\Models\SerieComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Series autorizadas y numeración correlativa sin huecos.
 *
 * ## La regla que no se negocia
 *
 * Un correlativo se consume al emitir el comprobante y **nunca se libera**. Si
 * el envío a SUNAT falla, el comprobante conserva su número y el reintento usa
 * el MISMO. La alternativa —devolver el número al contador si el envío
 * falló— parece más limpia pero abre un agujero: si dos emisiones concurrentes
 * fallan y devuelven el número, la siguiente lo reutiliza y SUNAT recibe dos
 * documentos con el mismo correlativo, o un hueco si una de ellas sí llegó.
 *
 * El único caso en que un número gastado no genera documento es la
 * comunicación de baja, y para eso existe un endpoint explícito.
 */
final class SerieService
{
    /**
     * Registra una serie nueva. El nombre se normaliza en mayúsculas porque
     * SUNAT las autoriza así y `b001` vs `B001` rompería el índice único solo
     * en MySQL, que es case-insensitive, pero no en SQLite.
     */
    public function registrar(
        int $empresaId,
        ?int $agenciaId,
        TipoComprobante $tipo,
        string $serie,
        ?User $actor = null,
    ): SerieComprobante {
        $serie = mb_strtoupper(trim($serie));

        if (! preg_match('/^[A-Z0-9]{4}$/', $serie)) {
            throw new DomainException('La serie debe tener exactamente 4 caracteres alfanuméricos.');
        }

        $existente = SerieComprobante::query()
            ->where('empresa_id', $empresaId)
            ->where('serie', $serie)
            ->first();

        if ($existente) {
            throw new DomainException("La serie {$serie} ya está registrada en esta empresa.");
        }

        return SerieComprobante::query()->create([
            'empresa_id' => $empresaId,
            'agencia_id' => $agenciaId,
            'tipo_documento' => $tipo->value,
            'serie' => $serie,
            'descripcion' => $tipo->denominacion(),
            'ultimo_correlativo' => 0,
            'estado' => true,
            'creado_por' => $actor?->id,
            'creado_at' => now(),
        ]);
    }

    /**
     * Devuelve la serie activa para emitir un tipo de comprobante, priorizando
     * la del punto de emisión del actor y cayendo a la de casa matriz.
     */
    public function serieParaEmitir(int $empresaId, ?int $agenciaId, TipoComprobante $tipo): SerieComprobante
    {
        $serie = $agenciaId !== null
            ? SerieComprobante::query()
                ->where('empresa_id', $empresaId)
                ->where('agencia_id', $agenciaId)
                ->where('tipo_documento', $tipo->value)
                ->where('estado', true)
                ->first()
            : null;

        $serie ??= SerieComprobante::query()
            ->where('empresa_id', $empresaId)
            ->whereNull('agencia_id')
            ->where('tipo_documento', $tipo->value)
            ->where('estado', true)
            ->first();

        if (! $serie) {
            throw new DomainException(
                "No hay ninguna serie activa de {$tipo->denominacion()} en este punto de emisión. Debe registrar una antes de emitir."
            );
        }

        return $serie;
    }

    /**
     * Reserva el siguiente correlativo de la serie.
     *
     * `lockForUpdate()` serializa a los emisores concurrentes: dos cajas que
     * facturen en el mismo segundo no pueden leer el mismo `ultimo_correlativo`.
     * En SQLite (el motor de los tests) es un no-op, pero el patrón es el
     * correcto en MySQL y los tests verifican la monotonía.
     *
     * @return int el correlativo reservado
     */
    public function siguienteCorrelativo(SerieComprobante $serie): int
    {
        return DB::transaction(function () use ($serie): int {
            $bloqueada = SerieComprobante::query()
                ->whereKey($serie->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $bloqueada->tieneCupo()) {
                throw new DomainException(
                    "La serie {$bloqueada->serie} alcanzó el límite de numeración autorizado por SUNAT. Debe solicitar una serie nueva."
                );
            }

            $siguiente = $bloqueada->ultimo_correlativo + 1;

            $bloqueada->update(['ultimo_correlativo' => $siguiente]);

            return $siguiente;
        });
    }

    /**
     * Formato que SUNAT exige en el nombre del archivo y en la impresión:
     * F001-00000123.
     */
    public function formatearNumero(string $serie, int $correlativo): string
    {
        return $serie.'-'.str_pad((string) $correlativo, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Nombre del archivo XML que SUNAT espera dentro del ZIP:
     * {RUC}-{TipoDoc}-{Serie}-{Correlativo}.xml. Si el nombre no coincide
     * exactamente, el Web Service rechaza el ZIP entero.
     */
    public function nombreArchivo(string $ruc, TipoComprobante $tipo, string $serie, int $correlativo): string
    {
        return sprintf(
            '%s-%s-%s-%s.xml',
            $ruc,
            $tipo->value,
            $serie,
            str_pad((string) $correlativo, 8, '0', STR_PAD_LEFT)
        );
    }
}
