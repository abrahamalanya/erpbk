<?php

namespace App\Modules\Caja\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCajaMovimientoRequest extends FormRequest
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
            'tipo' => ['required', 'string', 'in:ingreso,egreso'],
            'concepto_id' => ['required', 'integer', 'exists:conceptos,id'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'comprobante' => ['required_if:tipo,egreso', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'fotos_adicionales' => ['sometimes', 'array', 'max:10'],
            'fotos_adicionales.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
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
            'tipo.required' => 'El tipo es requerido',
            'tipo.in' => 'El tipo debe ser ingreso o egreso',
            'concepto_id.required' => 'El concepto es requerido',
            'concepto_id.exists' => 'El concepto seleccionado no existe',
            'monto.required' => 'El monto es requerido',
            'monto.min' => 'El monto debe ser mayor a cero',
            'comprobante.required_if' => 'El comprobante de pago es requerido para registrar un gasto',
            'fotos_adicionales.max' => 'No puedes subir más de 10 fotos adicionales',
        ];
    }
}
