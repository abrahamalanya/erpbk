<?php

namespace App\Nucleo\Exceptions;

use DomainException;

/**
 * El estado real del servidor contradice lo que la operación asume (por
 * ejemplo: registrar un cobro con la caja ya cerrada, o aperturar cuando ya hay
 * un ciclo abierto). A diferencia de un error de validación de datos, aquí la
 * operación **no se puede aplicar tal cual** porque el mundo cambió.
 *
 * Se responde `409 Conflict` con un `reason` distinguible para que la app
 * offline la marque como "revisar" (el servidor gana) en vez de "fallido", y
 * pueda explicar el motivo o auto-resolver casos benignos.
 *
 * @see \App\Nucleo\Exceptions\MotivoConflicto
 */
class ConflictoDeEstadoException extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $reason
    ) {
        parent::__construct($message);
    }
}
