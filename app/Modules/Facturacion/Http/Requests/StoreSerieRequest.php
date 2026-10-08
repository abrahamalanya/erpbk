<?php

namespace App\Modules\Facturacion\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSerieRequest extends FormRequest
{
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
            'tipo_documento' => ['required', Rule::in(['01', '03', '07'])],
            'serie' => ['required', 'string', 'size:4'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'limite_numeracion' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_documento.in' => 'El tipo de documento debe ser 01 (factura), 03 (boleta) o 07 (nota de crédito).',
            'serie.size' => 'La serie debe tener exactamente 4 caracteres.',
        ];
    }
}
