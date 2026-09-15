<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ActualizarCondicionesCreditoRequest extends FormRequest
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
            'tipo_interes' => ['nullable', Rule::in(['simple', 'compuesto'])],
            'tipo_cuota' => ['nullable', Rule::in(['diario', 'semanal', 'quincenal', 'mensual'])],
            'monto_prestamo' => ['nullable', 'numeric', 'min:0.01'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (blank($this->input('tipo_interes')) && blank($this->input('tipo_cuota')) && blank($this->input('monto_prestamo'))) {
                $validator->errors()->add('tipo_interes', 'Debes indicar al menos una condición a editar.');
            }
        });
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_interes.in' => 'El tipo de interés no es válido',
            'tipo_cuota.in' => 'El tipo de cuota no es válido',
            'monto_prestamo.min' => 'El monto del préstamo debe ser mayor a cero',
        ];
    }
}
