<?php

namespace App\Modules\Empresa\Http\Requests;

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
            'ruc' => ['nullable', 'string', 'max:11'],
            'razon_social' => ['nullable', 'string', 'max:255'],
            'domicilio_legal' => ['nullable', 'string', 'max:255'],
            'actividad_economica' => ['nullable', 'string', 'max:255'],
            'representante_legal' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'firma' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
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
