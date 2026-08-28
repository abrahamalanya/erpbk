<?php

namespace App\Modules\Usuario\Http\Requests;

use App\Modules\Usuario\Services\UserHierarchyService;
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
     * Accept a single `role` string as a one-element `roles` array so older
     * clients keep working while the API moves to multiple roles per user.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('roles') && $this->filled('role')) {
            $this->merge(['roles' => [$this->input('role')]]);
        }
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
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(app(UserHierarchyService::class)->assignableRoles($this->user()))],
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
