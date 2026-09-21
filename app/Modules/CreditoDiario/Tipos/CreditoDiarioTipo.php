<?php

namespace App\Modules\CreditoDiario\Tipos;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Tipos\CreditoTipo;
use App\Modules\CreditoDiario\Models\CreditoDiarioGarantia;
use App\Modules\Usuario\Models\User;
use Illuminate\Support\Collection;

/**
 * Crédito diario: igual que prendario en aprobar/rechazar/desembolsar/
 * liquidar/estados vencidos, pero sin ninguna prenda real y sin refrendo ni
 * adenda (bloqueados explícitamente en CreditoService::refrendar()/
 * adendar() — un préstamo de cronograma fijo no se "renueva", se paga por
 * cuotas vía pagarCuotas()). La garantía es un placeholder invisible
 * (CreditoDiarioGarantia) que solo existe para satisfacer el motor
 * compartido (CreditoService). Un vencido nunca pasa a tienda/remate — ver
 * pasaATiendaAlVencer().
 */
final class CreditoDiarioTipo implements CreditoTipo
{
    public function clave(): string
    {
        return 'diario';
    }

    public function garantiaModelo(): string
    {
        return CreditoDiarioGarantia::class;
    }

    public function maxGarantias(): ?int
    {
        return 1;
    }

    public function validarRegistro(User $actor, Collection $garantias, Cliente $cliente, array $datos): void
    {
        // Diario no agrega nada sobre las validaciones genéricas del motor.
    }

    public function atributosExtra(array $datos): array
    {
        return [];
    }

    public function requiereConformidadPreviaATienda(): bool
    {
        return false;
    }

    public function pasaATiendaAlVencer(): bool
    {
        return false;
    }

    public function vistaDocumento(string $tipoDocumento): string
    {
        return "modules.credito-prendario.documentos.{$tipoDocumento}";
    }

    public function generaFotosGarantia(): bool
    {
        return false;
    }

    public function generaStickerGarantia(): bool
    {
        return false;
    }

    public function limitaMontoPorValorizacionGarantia(): bool
    {
        return false;
    }
}
