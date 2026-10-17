<?php

namespace App\Modules\Sistemas\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePerfilAccesoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->hasRole('sistemas');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255', Rule::unique('perfiles_acceso')->ignore($this->route('perfilAcceso'))],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'activo' => ['required', 'boolean'],
            'modulos' => ['present', 'array'],
            'modulos.*' => ['required', 'string', 'distinct', 'exists:modulos,key'],
            'updated_at' => [$this->isMethod('PUT') || $this->isMethod('PATCH') ? 'required' : 'nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'Indica el nombre del perfil.',
            'nombre.unique' => 'Ya existe un perfil con este nombre.',
            'modulos.present' => 'Indica los módulos del perfil, incluso si la selección está vacía.',
            'modulos.*.exists' => 'Uno de los módulos seleccionados no existe.',
            'modulos.*.distinct' => 'No repitas módulos en el perfil.',
        ];
    }
}
