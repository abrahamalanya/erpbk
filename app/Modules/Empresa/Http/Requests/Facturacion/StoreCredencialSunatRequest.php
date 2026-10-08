<?php

namespace App\Modules\Empresa\Http\Requests\Facturacion;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request para crear/actualizar credenciales SUNAT.
 *
 * Permite subir el archivo .pfx y los datos de usuario/clave SOL.
 * La clave SOL y la contraseña del certificado se cifran automáticamente
 * por el modelo (cast `encrypted`).
 */
class StoreCredencialSunatRequest extends FormRequest
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
            'usuario_sol' => ['nullable', 'string', 'max:50'],
            'clave_sol' => ['nullable', 'string', 'max:100'],
            'certificado' => ['nullable', 'file', 'mimes:pfx,p12', 'max:10240'], // 10MB max
            'certificado_path' => ['nullable', 'string', 'max:500'], // Ruta existente (si no se sube nuevo)
            'certificado_password' => ['nullable', 'string', 'max:100'],
            'razon_social_certificado' => ['nullable', 'string', 'max:255'],
            'certificado_vence_en' => ['nullable', 'date', 'after_or_equal:today'],
            'modo_facturacion' => ['sometimes', Rule::in(['beta', 'produccion'])],
        ];
    }

    public function messages(): array
    {
        return [
            'certificado.mimes' => 'El certificado debe ser un archivo .pfx o .p12',
            'certificado.max' => 'El certificado no puede superar 10MB',
            'certificado_vence_en.after_or_equal' => 'La fecha de vencimiento no puede ser anterior a hoy',
            'modo_facturacion.in' => 'El modo debe ser beta o produccion',
        ];
    }

    /**
     * Prepara los datos para validación: si se sube archivo, no exigir certificado_password
     * (se valida en el controlador al procesar).
     */
    protected function prepareForValidation(): void
    {
        if ($this->hasFile('certificado') && ! $this->filled('certificado_password')) {
            // Permitir que falte la contraseña si se sube archivo (se pedirá en validación posterior)
            $this->merge(['certificado_password' => '']);
        }
    }
}