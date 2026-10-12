<?php

namespace App\Modules\Planilla\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePlanillaLineasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Cada línea es un trabajador del mes. `descuentos_devengado` es el total
     * que se le va a descontar a lo largo del tiempo; lo que se le descuenta
     * ESTE mes lo calcula el servicio de sus descuentos pendientes (no se
     * manda desde el request para que nadie pueda inflar un descuento).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.user_id' => ['required', 'integer', 'exists:users,id', 'distinct'],
            'lineas.*.ingresos' => ['required', 'numeric', 'min:0'],
            'lineas.*.bonificaciones' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.situacion' => ['nullable', 'string', 'max:50'],
            'lineas.*.descuentos_devengado' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lineas.required' => 'Debes registrar al menos un trabajador',
            'lineas.*.user_id.required' => 'Cada línea necesita un trabajador',
            'lineas.*.user_id.distinct' => 'El trabajador no puede repetirse en la misma planilla',
            'lineas.*.ingresos.required' => 'El ingreso del trabajador es requerido',
            'lineas.*.ingresos.min' => 'El ingreso no puede ser negativo',
        ];
    }
}
