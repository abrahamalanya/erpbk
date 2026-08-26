<?php

namespace App\Modules\CreditoPrendario\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdendarCreditoRequest extends FormRequest
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
            'monto_pagado' => ['required', 'numeric', 'min:0.01'],
            // Opcionales: un asesor solo cobra el interés (el sucesor
            // conserva la tasa/tipo de cuota actuales) — un admin puede
            // editarlas aquí mismo o después, ya con el crédito pendiente,
            // vía CreditoPrendarioController::editar()/actualizarInteres().
            'interes' => ['nullable', 'numeric', 'min:0'],
            'tipo_cuota' => ['nullable', Rule::in(['diario', 'semanal', 'quincenal', 'mensual'])],
            'medio' => ['required', 'string', Rule::in(['efectivo', 'yape', 'plin', 'transferencia'])],
            'comprobante' => ['required_unless:medio,efectivo', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
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
            'monto_pagado.required' => 'El monto pagado es requerido',
            'monto_pagado.min' => 'El monto pagado debe ser mayor a cero',
            'interes.min' => 'La tasa de interés no puede ser negativa',
            'tipo_cuota.in' => 'El tipo de cuota no es válido',
            'medio.required' => 'El medio de cobro es requerido',
            'medio.in' => 'El medio de cobro no es válido',
            'comprobante.required_unless' => 'Debes subir un comprobante para este medio de cobro',
        ];
    }
}
