<?php

namespace App\Modules\Empresa\Http\Requests;

use App\Nucleo\Support\DocumentoIdentidad;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmpresaRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'max:255'],
            'prefijo' => [
                'nullable', 'string', 'max:255',
                'regex:/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
                'unique:empresas,prefijo',
            ],
            'ruc' => [
                'nullable', 'string', 'size:11',
                Rule::unique('empresas', 'ruc')->ignore($this->route('empresa')),
                // El dígito verificador módulo 11 se comprueba antes de que el
                // RUC llegue al comprobante: un RUC mal tecleado no falla al
                // registrar el cliente, falla al enviar a SUNAT.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (filled($value) && ! DocumentoIdentidad::rucEsValido((string) $value)) {
                        $fail('El RUC no es válido: el dígito verificador no coincide.');
                    }
                },
            ],
            'razon_social' => ['nullable', 'string', 'max:255'],
            'domicilio_legal' => ['nullable', 'string', 'max:255'],
            'domicilio_calle' => ['nullable', 'string', 'max:150'],
            'domicilio_numero' => ['nullable', 'string', 'max:20'],
            'domicilio_interior' => ['nullable', 'string', 'max:20'],
            'ubigeo_distrito_id' => ['nullable', 'integer', 'exists:ubigeo_distritos,id'],
            'correo_facturacion' => ['nullable', 'email', 'max:255'],
            'telefono_facturacion' => ['nullable', 'string', 'max:30'],
            'actividad_economica' => ['nullable', 'string', 'max:255'],
            'representante_legal' => ['nullable', 'string', 'max:255'],
            'apoderado_legal' => ['nullable', 'string', 'max:255'],
            'celular_cobranzas' => ['nullable', 'string', 'max:30'],
            'logo' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'firma' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'estado' => ['sometimes', Rule::in(['activo', 'inactivo'])],
            'modo_facturacion' => ['sometimes', Rule::in(['beta', 'produccion'])],
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
            'prefijo.regex' => 'El prefijo debe ser un dominio válido, por ejemplo credimasperu.com',
            'prefijo.unique' => 'Ya existe una empresa con este prefijo',
            'estado.in' => 'El estado debe ser activo o inactivo',
        ];
    }
}
