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
            'telefono' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:500'],
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
