<?php

namespace App\Modules\Credito\Tipos;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A variant of the shared crédito engine (CreditoService). Every
 * tipo reuses the same máquina de estados, cronograma, refrendo/adenda/
 * liquidación and integración con caja; this contract carries only the
 * points where a tipo genuinely differs from prendario.
 */
interface CreditoTipo
{
    /**
     * Value stored in creditos_prendarios.tipo_credito and the morph alias
     * of the garantía model.
     */
    public function clave(): string;

    /**
     * FQCN of the garantía model backing this tipo (uses the EsGarantia
     * trait): Bien for prendario, Vehiculo for vehicular, Inmueble for
     * hipotecario.
     *
     * @return class-string<Model>
     */
    public function garantiaModelo(): string;

    /**
     * Max garantías allowed on a single crédito, or null for no limit.
     */
    public function maxGarantias(): ?int;

    /**
     * Tipo-specific validation on top of the engine's generic checks (open
     * caja, same cliente, garantías disponibles, monto <= valorizaciones).
     * Throws DomainException on failure.
     *
     * @param  Collection<int, Model>  $garantias
     * @param  array<string, mixed>  $datos
     */
    public function validarRegistro(User $actor, Collection $garantias, Cliente $cliente, array $datos): void;

    /**
     * Extra columns to persist on the creditos row at registrar() time
     * (e.g. supervisado_por for vehicular).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public function atributosExtra(array $datos): array;

    /**
     * Whether a vencido crédito must clear a conformidad (notario/abogado)
     * step before it can move to en_venta. Prendario: false.
     */
    public function requiereConformidadPreviaATienda(): bool;

    /**
     * Blade view name for a generated documento (contrato, declaracion,
     * fotos, devolucion, adenda, cronograma).
     */
    public function vistaDocumento(string $tipoDocumento): string;
}
