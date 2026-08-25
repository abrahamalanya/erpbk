<?php

namespace App\Modules\Sistemas\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConceptoRequest extends FormRequest
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
            // Only sistemas ever reaches this request (ConceptoPolicy::create()
            // is otherwise unconditionally false), and sistemas has no empresa_id
            // of its own — so unlike a regular tenant resource, the empresa this
            // concepto belongs to must always be picked explicitly.
            'empresa_id' => ['required', 'integer', 'exists:empresas,id'],
            'tipo' => ['required', 'string', 'in:ingreso,gasto'],
            'nombre' => [
                'required', 'string', 'max:255',
                Rule::unique('conceptos')
                    ->where('empresa_id', $this->input('empresa_id'))
                    ->where('tipo', $this->input('tipo')),
            ],
            'activo' => ['sometimes', 'boolean'],
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
            'empresa_id.required' => 'La empresa es requerida',
            'empresa_id.exists' => 'La empresa seleccionada no existe',
            'tipo.required' => 'El tipo es requerido',
            'tipo.in' => 'El tipo debe ser ingreso o gasto',
            'nombre.required' => 'El nombre es requerido',
            'nombre.unique' => 'Ya existe un concepto con ese nombre para este tipo',
        ];
    }
}
