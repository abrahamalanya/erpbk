<?php

namespace App\Modules\Simulador\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalcularSimulacionCreditoRequest extends FormRequest
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
            'tipo_credito' => ['required', Rule::in(['prendario', 'vehicular', 'hipotecario', 'diario'])],
            'monto_prestamo' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'interes' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999.99'],
            'tipo_interes' => ['nullable', Rule::in(['simple', 'compuesto'])],
            'tipo_cuota' => ['required', Rule::in(['diario', 'semanal', 'quincenal', 'mensual'])],
            'modo_calculo' => ['sometimes', Rule::in(['numero_cuotas', 'importe_cuota'])],
            'numero_cuotas' => ['nullable', 'required_if:modo_calculo,numero_cuotas', 'prohibited_if:modo_calculo,importe_cuota', 'integer', 'min:1', 'max:600'],
            'importe_cuota' => ['nullable', 'required_if:modo_calculo,importe_cuota', 'prohibited_unless:modo_calculo,importe_cuota', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'numero_cuotas.required_if' => 'Indica el número de cuotas.',
            'importe_cuota.required_if' => 'Indica el importe deseado de cuota.',
            'numero_cuotas.prohibited_if' => 'Elige solo una modalidad de cálculo.',
            'importe_cuota.prohibited_unless' => 'El importe de cuota corresponde a la modalidad por importe.',
        ];
    }
}
