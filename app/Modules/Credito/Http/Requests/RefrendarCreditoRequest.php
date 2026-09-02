<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefrendarCreditoRequest extends FormRequest
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
            'medio.required' => 'El medio de cobro es requerido',
            'medio.in' => 'El medio de cobro no es válido',
            'comprobante.required_unless' => 'Debes subir un comprobante para este medio de cobro',
        ];
    }
}
