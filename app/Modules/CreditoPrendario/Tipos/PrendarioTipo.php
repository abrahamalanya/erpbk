<?php

namespace App\Modules\CreditoPrendario\Tipos;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Tipos\CreditoTipo;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Usuario\Models\User;
use Illuminate\Support\Collection;

/**
 * The original crédito prendario: garantía is a bien mueble, no conformidad
 * step before tienda, no extra columns. All the behaviour lives in the
 * shared engine (CreditoService).
 */
final class PrendarioTipo implements CreditoTipo
{
    public function clave(): string
    {
        return 'prendario';
    }

    public function garantiaModelo(): string
    {
        return Bien::class;
    }

    public function maxGarantias(): ?int
    {
        return null;
    }

    public function validarRegistro(User $actor, Collection $garantias, Cliente $cliente, array $datos): void
    {
        // Prendario adds nothing on top of the engine's generic checks.
    }

    public function atributosExtra(array $datos): array
    {
        return [];
    }

    public function requiereConformidadPreviaATienda(): bool
    {
        return false;
    }

    public function vistaDocumento(string $tipoDocumento): string
    {
        return "modules.credito-prendario.documentos.{$tipoDocumento}";
    }
}
