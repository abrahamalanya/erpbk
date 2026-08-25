<?php

namespace App\Modules\Caja\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBilletajeRequest extends FormRequest
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
            'monto' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['required', 'string', 'max:500'],
            'medio_recepcion' => ['required', 'string', 'in:efectivo,yape,plin,transferencia'],
            'datos_recepcion' => ['required_unless:medio_recepcion,efectivo', 'nullable', 'string', 'max:255'],
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
            'monto.required' => 'El monto es requerido',
            'monto.numeric' => 'El monto debe ser un número',
            'monto.min' => 'El monto debe ser mayor a cero',
            'motivo.required' => 'Debes indicar el motivo del billetaje',
            'medio_recepcion.required' => 'Debes indicar por qué medio quieres recibir el dinero',
            'medio_recepcion.in' => 'El medio de recepción debe ser efectivo, yape, plin o transferencia',
            'datos_recepcion.required_unless' => 'Debes indicar a dónde enviar el dinero (número o cuenta)',
        ];
    }
}
