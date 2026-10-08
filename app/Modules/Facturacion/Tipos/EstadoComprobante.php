<?php

namespace App\Modules\Facturacion\Tipos;

use DomainException;

/**
 * Ciclo de vida de un comprobante. Cada estado sabe a qué estados puede pasar
 * y por qué, para que ni el servicio ni la UI dejen que se salten pasos — la
 * numeración consumida y lo comunicado a SUNAT no se pueden deshacer.
 *
 * La cadena normal es:
 *
 *   borrador → generado → enviado → aceptado → (anulado | sustituido)
 *
 * `generado → error` es el reintento técnico (conserva el número, y por eso
 * nunca vuelve atrás). `enviado → observado` persiste: SUNAT acepta el
 * documento pero señala detalles a corregir en el siguiente.
 */
enum EstadoComprobante: string
{
    /** Preparado pero sin correlativo asignado. Se puede editar libremente. */
    case Borrador = 'borrador';

    /** XML firmado y PDF listos, pendiente de enviar. Ya consumió correlativo. */
    case Generado = 'generado';

    /** Enviado a SUNAT, esperando el CDR. */
    case Enviado = 'enviado';

    /** SUNAT aceptó el comprobante. Es el único estado con valor fiscal pleno. */
    case Aceptado = 'aceptado';

    /** Aceptado con observaciones. Sigue siendo válido. */
    case Observado = 'observado';

    /** SUNAT lo rechazó. No persiste ante SUNAT: el número se consumió igual. */
    case Rechazado = 'rechazado';

    /** Fallo técnico (red, WSDL, timeout). Reintentable con el mismo número. */
    case Error = 'error';

    /** Anulado: local si nunca se comunicó, o con baja confirmada si se aceptó. */
    case Anulado = 'anulado';

    /** Existe una nota de crédito que corrige a este comprobante. */
    case Sustituido = 'sustituido';

    /**
     * ¿El número ya fue irreversible? A partir de aquí no se puede liberar el
     * correlativo ni reutilizarlo: el único camino es nota de crédito o baja.
     */
    public function numeroConsumido(): bool
    {
        return $this !== self::Borrador;
    }

    /**
     * ¿Tiene valor fiscal? Solo lo aceptado (y lo observado, que también lo
     * es) cuentan como comprobante válido ante SUNAT.
     */
    public function esValido(): bool
    {
        return in_array($this, [self::Aceptado, self::Observado, self::Sustituido], true);
    }

    /**
     * ¿Se puede intentar (o reintentar) el envío a SUNAT?
     */
    public function enviable(): bool
    {
        return in_array($this, [self::Generado, self::Error], true);
    }

    /**
     * ¿Todavía se puede cancelar sin comunicarla a SUNAT? Solo si nunca se
     aceptó; los aceptados exigen comunicación de baja o nota de crédito.
     */
    public function anulableLocalmente(): bool
    {
        return in_array($this, [self::Borrador, self::Generado, self::Enviado, self::Error, self::Rechazado], true);
    }

    /**
     * @return list<self>
     */
    public function transicionesValidas(): array
    {
        return match ($this) {
            self::Borrador => [self::Generado],
            self::Generado => [self::Enviado, self::Error, self::Anulado],
            self::Enviado => [self::Aceptado, self::Observado, self::Rechazado, self::Error],
            self::Error => [self::Enviado, self::Anulado],
            self::Aceptado => [self::Anulado, self::Sustituido],
            self::Observado => [self::Anulado, self::Sustituido],
            self::Rechazado => [self::Anulado],
            self::Anulado, self::Sustituido => [],
        };
    }

    public function puedeTransicionarA(self $destino): bool
    {
        return in_array($destino, $this->transicionesValidas(), true);
    }

    /**
     * @throws DomainException si la transición no está permitida.
     */
    public function asegurarTransicionA(self $destino): void
    {
        if (! $this->puedeTransicionarA($destino)) {
            throw new DomainException(
                "No se puede pasar un comprobante de '{$this->value}' a '{$destino->value}'."
            );
        }
    }
}
