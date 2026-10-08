<?php

namespace App\Modules\Facturacion\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConfiguracionFacturacionRequest extends FormRequest
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
            'modo' => ['sometimes', 'string', 'in:beta,produccion'],
            'igv_porcentaje' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'moneda' => ['sometimes', 'string', 'in:PEN,USD'],
            'interes_incluye_igv' => ['sometimes', 'boolean'],
            'serie_factura_defecto' => ['nullable', 'string', 'max:4'],
            'serie_boleta_defecto' => ['nullable', 'string', 'max:4'],
            'bloquear_si_supera_umbral' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'modo.in' => 'El modo debe ser beta o produccion.',
            'moneda.in' => 'La moneda debe ser PEN o USD.',
            'igv_porcentaje.max' => 'El IGV no puede superar 100%.',
        ];
    }
}
