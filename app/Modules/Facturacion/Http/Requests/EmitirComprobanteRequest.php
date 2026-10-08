<?php

namespace App\Modules\Facturacion\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EmitirComprobanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * La emisión no lleva datos en el cuerpo: todo se calcula desde el
     * crédito (base imponible, receptor, serie). El único dato que podría
     * venir es una observación para el comprobante.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'observaciones' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'observaciones.max' => 'La observación no puede superar los 1000 caracteres.',
        ];
    }
}
