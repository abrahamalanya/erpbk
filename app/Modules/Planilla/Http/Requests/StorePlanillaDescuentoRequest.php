<?php

namespace App\Modules\Planilla\Http\Requests;

use App\Modules\Planilla\Tipos\DescuentoTipo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanillaDescuentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `cuota` es lo que se descuenta por mes. Nulo = todo de una vez (un
     * adelanto); con valor = en cuotas (un préstamo, una falta), y no puede
     * ser mayor que el monto total.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'tipo' => ['required', 'string', Rule::in(array_column(DescuentoTipo::cases(), 'value'))],
            'concepto' => ['required', 'string', 'max:255'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'cuota' => ['nullable', 'numeric', 'min:0.01', 'lte:monto'],
            'fecha_registro' => ['required', 'date'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'El trabajador es requerido',
            'tipo.required' => 'El tipo de descuento es requerido',
            'tipo.in' => 'El tipo de descuento no es válido',
            'concepto.required' => 'El concepto es requerido',
            'monto.required' => 'El monto es requerido',
            'monto.min' => 'El monto debe ser mayor a cero',
            'cuota.lte' => 'La cuota no puede ser mayor que el monto total',
            'fecha_registro.required' => 'La fecha de registro es requerida',
        ];
    }
}
