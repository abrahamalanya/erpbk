<?php

namespace App\Modules\Credito\Http\Requests;

use App\Modules\Credito\Models\CreditoExpedienteDocumento;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarExpedienteDocumentoRequest extends FormRequest
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
            'rol' => ['required', Rule::in(CreditoExpedienteDocumento::ROLES)],
            'seccion' => ['required', Rule::in(CreditoExpedienteDocumento::SECCIONES)],
            // Solo imágenes: dompdf no puede embeber PDFs; los recibos / copia
            // literal se suben como captura, igual que en el expediente de ejemplo.
            'archivos' => ['required', 'array', 'min:1'],
            'archivos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivos.required' => 'Debes seleccionar al menos una imagen',
            'archivos.*.image' => 'Solo se aceptan imágenes (JPG, PNG, WEBP)',
        ];
    }
}
