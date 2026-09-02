<?php

namespace App\Modules\Credito\Tipos;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Base for tipos de crédito con garantía formal (vehicular, hipotecario):
 * exigen dirección y referencia del cliente, un "supervisado por"
 * (administrador de agencia o supervisor, informativo) y la conformidad del
 * notario/abogado antes de pasar a la tienda. Admiten N garantías.
 *
 * Las subclases aportan clave(), garantiaModelo(), etiqueta() y el módulo
 * de vistas propio para el contrato.
 */
abstract class CreditoTipoSupervisado implements CreditoTipo
{
    /**
     * Nombre corto del tipo para los mensajes de error (p.ej. "vehicular").
     */
    abstract protected function etiqueta(): string;

    /**
     * Carpeta de vistas del módulo con la plantilla propia del contrato
     * (p.ej. "credito-vehicular"). El resto de documentos se reutilizan de
     * prendario.
     */
    abstract protected function moduloVistas(): string;

    public function maxGarantias(): ?int
    {
        return null;
    }

    public function validarRegistro(User $actor, Collection $garantias, Cliente $cliente, array $datos): void
    {
        if (blank($cliente->direccion) || blank($cliente->referencia)) {
            throw new DomainException("Para un crédito {$this->etiqueta()} el cliente debe tener dirección y referencia registradas.");
        }

        $supervisorId = $datos['supervisado_por'] ?? null;

        if (blank($supervisorId)) {
            throw new DomainException("Debes indicar el usuario que supervisa el crédito {$this->etiqueta()}.");
        }

        $supervisor = User::query()
            ->where('empresa_id', $cliente->empresa_id)
            ->whereKey($supervisorId)
            ->first();

        if (! $supervisor || ! $supervisor->hasAnyRole(['administrador_agencia', 'supervisor'])) {
            throw new DomainException('El supervisor indicado debe ser un administrador de agencia o supervisor de la empresa.');
        }
    }

    public function atributosExtra(array $datos): array
    {
        return ['supervisado_por' => $datos['supervisado_por'] ?? null];
    }

    public function requiereConformidadPreviaATienda(): bool
    {
        return true;
    }

    /**
     * Solo el contrato tiene texto y campos propios del tipo; declaración,
     * fotos, devolución, cronograma y adenda son genéricos y se reutilizan
     * de prendario.
     *
     * @var list<string>
     */
    private const VISTAS_PROPIAS = ['contrato'];

    public function vistaDocumento(string $tipoDocumento): string
    {
        $modulo = in_array($tipoDocumento, self::VISTAS_PROPIAS, true)
            ? $this->moduloVistas()
            : 'credito-prendario';

        return "modules.{$modulo}.documentos.{$tipoDocumento}";
    }
}
