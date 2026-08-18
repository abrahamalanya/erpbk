<?php

namespace App\Modules\CreditoPrendario\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditoPrendarioRequest extends FormRequest
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
            'bien_id' => ['required', 'integer', 'exists:bienes,id'],
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
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
            'bien_id.required' => 'El bien es requerido',
            'bien_id.exists' => 'El bien indicado no existe',
            'cliente_id.required' => 'El cliente es requerido',
            'cliente_id.exists' => 'El cliente indicado no existe',
            'monto_prestamo.required' => 'El monto del préstamo es requerido',
            'tipo_cuota.required' => 'El tipo de cuota es requerido',
            'tipo_cuota.in' => 'El tipo de cuota no es válido',
        ];
    }
}
