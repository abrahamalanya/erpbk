<?php

namespace App\Modules\Empresa\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgenciaRequest extends FormRequest
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
            'estado' => ['sometimes', Rule::in(['activo', 'inactivo'])],
            'empresa_id' => [
                Rule::requiredIf(fn (): bool => $this->user()->hasRole('sistemas')),
                'nullable',
                'integer',
                'exists:empresas,id',
            ],
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
            'empresa_id.required' => 'La empresa es requerida',
            'empresa_id.exists' => 'La empresa seleccionada no existe',
        ];
    }
}
