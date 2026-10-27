<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DesembolsarCreditoRequest extends FormRequest
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
            'numero_cuotas' => ['nullable', 'integer', 'min:1'],
            'interes' => ['nullable', 'numeric', 'min:0'],
            'fecha_desembolso' => ['nullable', 'date', 'before_or_equal:today'],
            'condiciones_esperadas' => ['sometimes', 'array:monto_prestamo,interes,tipo_interes,tipo_cuota,numero_cuotas,fecha_desembolso', 'size:6'],
            'condiciones_esperadas.monto_prestamo' => ['required_with:condiciones_esperadas', 'numeric', 'gt:0'],
            'condiciones_esperadas.interes' => ['required_with:condiciones_esperadas', 'numeric', 'min:0'],
            'condiciones_esperadas.tipo_interes' => ['required_with:condiciones_esperadas', 'in:simple,compuesto'],
            'condiciones_esperadas.tipo_cuota' => ['required_with:condiciones_esperadas', 'in:diario,semanal,quincenal,mensual'],
            'condiciones_esperadas.numero_cuotas' => ['present_with:condiciones_esperadas', 'nullable', 'integer', 'min:1'],
            'condiciones_esperadas.fecha_desembolso' => ['present_with:condiciones_esperadas', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_desembolso.date' => 'La fecha de desembolso no es válida',
            'fecha_desembolso.before_or_equal' => 'La fecha de desembolso no puede ser futura',
        ];
    }
}
