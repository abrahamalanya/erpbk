<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bien_ids' => ['required', 'array', 'min:1'],
            'bien_ids.*' => ['integer', 'distinct', 'exists:bienes,id'],
            'monto_prestamo' => ['required', 'numeric', 'min:0.01'],
            'interes' => ['nullable', 'numeric', 'min:0'],
            'tipo_cuota' => ['required', Rule::in(['diario', 'semanal', 'quincenal', 'mensual'])],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bien_ids.required' => 'Debes seleccionar al menos un bien',
            'bien_ids.min' => 'Debes seleccionar al menos un bien',
            'bien_ids.*.exists' => 'Uno de los bienes indicados no existe',
            'bien_ids.*.distinct' => 'No repitas el mismo bien',
            'monto_prestamo.required' => 'El monto del préstamo es requerido',
            'tipo_cuota.required' => 'El tipo de cuota es requerido',
            'tipo_cuota.in' => 'El tipo de cuota no es válido',
        ];
    }
}
