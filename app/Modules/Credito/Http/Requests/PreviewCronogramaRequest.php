<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewCronogramaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'monto_prestamo' => ['required', 'numeric', 'min:0.01'],
            'interes' => ['required', 'numeric', 'min:0'],
            'tipo_cuota' => ['required', Rule::in(['diario', 'semanal', 'quincenal', 'mensual'])],
            'numero_cuotas' => ['nullable', 'integer', 'min:1', 'max:60'],
            'tipo_interes' => ['nullable', Rule::in(['simple', 'compuesto'])],
            'tipo_credito' => ['nullable', Rule::in(['prendario', 'vehicular', 'hipotecario', 'diario'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'monto_prestamo.required' => 'El monto del préstamo es requerido',
            'interes.required' => 'El interés es requerido',
            'tipo_cuota.required' => 'El tipo de cuota es requerido',
            'tipo_cuota.in' => 'El tipo de cuota no es válido',
        ];
    }
}
