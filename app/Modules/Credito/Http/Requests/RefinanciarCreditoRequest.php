<?php

namespace App\Modules\Credito\Http\Requests;

use App\Modules\Credito\Http\Requests\Concerns\ValidaMotivoDescuento;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RefinanciarCreditoRequest extends FormRequest
{
    use ValidaMotivoDescuento;

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
            // Opcional a propósito: 0 o ausente refinancia el 100% de la
            // deuda (capital + interés + mora), sin exigir un pago ahora.
            'monto_pagado' => ['nullable', 'numeric', 'min:0'],
            'medio' => ['required', 'string', Rule::in(['efectivo', 'yape', 'plin', 'transferencia'])],
            'comprobante' => ['required_unless:medio,efectivo', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'descuento' => ['nullable', 'numeric', 'min:0'],
            'motivo_descuento' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->aplicarReglaMotivoDescuento($validator);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'medio.required' => 'El medio de cobro es requerido',
            'medio.in' => 'El medio de cobro no es válido',
            'comprobante.required_unless' => 'Debes subir un comprobante para este medio de cobro',
            'motivo_descuento.required' => 'Debes indicar el motivo del descuento',
        ];
    }
}
