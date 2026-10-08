<?php

namespace App\Modules\Empresa\Http\Requests;

use App\Nucleo\Support\DocumentoIdentidad;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgenciaRequest extends FormRequest
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
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'ruc' => [
                'nullable', 'string', 'size:11',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (filled($value) && ! DocumentoIdentidad::rucEsValido((string) $value)) {
                        $fail('El RUC no es válido: el dígito verificador no coincide.');
                    }
                },
            ],
            'domicilio_calle' => ['nullable', 'string', 'max:150'],
            'domicilio_numero' => ['nullable', 'string', 'max:20'],
            'domicilio_interior' => ['nullable', 'string', 'max:20'],
            'ubigeo_distrito_id' => ['nullable', 'integer', 'exists:ubigeo_distritos,id'],
            'correo_facturacion' => ['nullable', 'email', 'max:255'],
            'codigo_establecimiento' => ['nullable', 'string', 'max:4'],
            'estado' => ['sometimes', Rule::in(['activo', 'inactivo'])],
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
            'nombre.required' => 'El nombre es requerido',
            'estado.in' => 'El estado debe ser activo o inactivo',
        ];
    }
}
