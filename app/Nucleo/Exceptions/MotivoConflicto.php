<?php

namespace App\Nucleo\Exceptions;

/**
 * Motivos distinguibles de un `ConflictoDeEstadoException`. La app offline lee
 * el `reason` de la respuesta 409 para explicar por qué el servidor no aplicó
 * la operación (ver `offline/sync.ts` y la bandeja de sincronización).
 */
final class MotivoConflicto
{
    /** No hay un ciclo de caja abierto: la caja está cerrada o nunca se aperturó. */
    public const CAJA_CERRADA = 'caja_cerrada';

    /** Ya existe un ciclo de caja abierto. */
    public const CAJA_ABIERTA = 'caja_abierta';

    /** El crédito no está en estado activo/vencido: liquidado, cerrado o anulado. */
    public const CREDITO_ESTADO_INVALIDO = 'credito_estado_invalido';

    /** El crédito ya no tiene cuotas pendientes (fue liquidado o ya se pagaron). */
    public const CREDITO_SIN_CUOTAS = 'credito_sin_cuotas';

    /** El crédito ya alcanzó el máximo de refrendos permitidos. */
    public const CREDITO_MAXIMO_REFRENDOS = 'credito_maximo_refrendos';

    /** Crédito diario sin el video de evidencia + ubicación cargados (requisito previo a desembolsar). */
    public const EVIDENCIA_DESEMBOLSO_PENDIENTE = 'evidencia_desembolso_pendiente';

    /** La caja del actor no tiene saldo suficiente para desembolsar el monto del crédito. */
    public const CAJA_SALDO_INSUFICIENTE = 'caja_saldo_insuficiente';

    /** Las condiciones aprobadas cambiaron mientras el dispositivo estaba sin conexión. */
    public const CREDITO_CONDICIONES_CAMBIADAS = 'credito_condiciones_cambiadas';

    private function __construct()
    {
        // Solo constantes.
    }
}
