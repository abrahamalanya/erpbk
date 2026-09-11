<?php

namespace App\Modules\Cliente\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClienteRequest extends FormRequest
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
            'apellido' => ['sometimes', 'required', 'string', 'max:255'],
            'tipo_documento' => ['sometimes', 'required', Rule::in(['dni', 'ce', 'pasaporte'])],
            'numero_documento' => [
                'sometimes', 'required', 'string', 'max:20',
                Rule::unique('clientes', 'numero_documento')
                    ->where(fn ($query) => $query->where('empresa_id', $this->route('cliente')->empresa_id))
                    ->ignore($this->route('cliente')),
            ],
            'fecha_nacimiento' => ['nullable', 'date'],
            'sexo' => ['nullable', Rule::in(['m', 'f'])],
            'estado_civil' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ubigeo_distrito_id' => ['nullable', 'integer', 'exists:ubigeo_distritos,id'],
            'referencia' => ['nullable', 'string', 'max:500'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'direccion_negocio' => ['nullable', 'string', 'max:255'],
            'ubigeo_distrito_negocio_id' => ['nullable', 'integer', 'exists:ubigeo_distritos,id'],
            'referencia_negocio' => ['nullable', 'string', 'max:500'],
            'latitud_negocio' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud_negocio' => ['nullable', 'numeric', 'between:-180,180'],
            'estado' => ['sometimes', Rule::in(['activo', 'inactivo'])],
            'foto_cliente' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'foto_dni' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'foto_dni_reverso' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'foto_casa' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'foto_negocio' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
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
            'apellido.required' => 'El apellido es requerido',
            'numero_documento.unique' => 'Ya existe un cliente con este número de documento',
        ];
    }
}
