<?php

namespace App\Modules\Usuario\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
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
            'dni' => [
                'sometimes', 'required', 'string', 'regex:/^\d{8}$/',
                Rule::unique('users', 'dni')->ignore($this->route('user')),
            ],
            'telefono' => ['nullable', 'string', 'max:20'],
            'estado' => ['sometimes', Rule::in(['activo', 'inactivo'])],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
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
            'dni.regex' => 'El DNI debe tener 8 dígitos',
            'dni.unique' => 'Ya existe un usuario con este DNI',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
        ];
    }
}
