<?php

namespace App\Modules\Credito\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/**
 * Regla compartida por los Request de cobro (refrendar/adendar/liquidar):
 * `motivo_descuento` solo es obligatorio cuando `descuento` viene mayor a
 * cero — un descuento de 0/ausente (el caso normal, sin condonar nada) no
 * debe forzar al asesor a escribir un motivo vacío.
 *
 * Se resuelve con Validator::sometimes() en vez de una regla de cierre
 * dentro de rules(): Laravel no evalúa una regla no-implícita (como un
 * Closure suelto) cuando el campo ('motivo_descuento') está completamente
 * ausente del payload, que es justo el caso normal que hay que cubrir aquí.
 */
trait ValidaMotivoDescuento
{
    protected function aplicarReglaMotivoDescuento(Validator $validator): void
    {
        $validator->sometimes(
            'motivo_descuento',
            'required',
            fn (object $input): bool => (float) ($input->descuento ?? 0) > 0,
        );
    }
}
