<?php

namespace App\Modules\Sistemas\Services;

use App\Modules\Sistemas\Models\Concepto;
use App\Modules\Usuario\Models\User;
use DomainException;

final class ConceptoService
{
    public function crear(User $actor, array $datos): Concepto
    {
        return Concepto::query()->create([
            'empresa_id' => $datos['empresa_id'],
            'tipo' => $datos['tipo'],
            'nombre' => $datos['nombre'],
            'activo' => $datos['activo'] ?? true,
            'creado_por' => $actor->id,
        ]);
    }

    public function actualizar(Concepto $concepto, array $datos): Concepto
    {
        $concepto->update([
            'nombre' => $datos['nombre'] ?? $concepto->nombre,
            'activo' => $datos['activo'] ?? $concepto->activo,
        ]);

        return $concepto->fresh();
    }

    public function eliminar(Concepto $concepto): void
    {
        if ($concepto->cajaMovimientos()->exists()) {
            throw new DomainException('No se puede eliminar un concepto que ya tiene movimientos registrados. Desactívalo en su lugar.');
        }

        $concepto->delete();
    }
}
