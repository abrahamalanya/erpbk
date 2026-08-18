<?php

namespace App\Modules\Usuario\Http\Requests;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;
use App\Modules\Usuario\Services\UserHierarchyService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
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
        $actor = $this->user();
        $hierarchy = app(UserHierarchyService::class);

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'estado' => ['sometimes', Rule::in(['activo', 'inactivo'])],
            'role' => ['required', 'string', Rule::in($hierarchy->assignableRoles($actor))],
            'empresa_id' => [
                Rule::requiredIf(fn (): bool => $actor->hasRole('sistemas')),
                'nullable', 'integer', 'exists:empresas,id',
            ],
            'agencia_id' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('role'), ['administrador_agencia', 'peinadora', 'supervisor', 'asesor'], true)
                    && ! $actor->hasRole('administrador_agencia')),
                'nullable', 'integer', 'exists:agencias,id',
            ],
            'supervisor_id' => [
                Rule::requiredIf(fn (): bool => $this->input('role') === 'asesor'),
                'nullable', 'integer', 'exists:users,id',
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->user();
            $role = $this->input('role');

            if (! is_string($role)) {
                return;
            }

            $agenciaLevel = in_array($role, ['administrador_agencia', 'peinadora', 'supervisor', 'asesor'], true);
            $agenciaId = $actor->hasRole('administrador_agencia') ? $actor->agencia_id : $this->input('agencia_id');
            $empresaId = $actor->hasRole('sistemas') ? $this->input('empresa_id') : $actor->empresa_id;

            if ($agenciaLevel && $agenciaId) {
                $agencia = Agencia::find($agenciaId);

                if ($agencia && $empresaId && (int) $agencia->empresa_id !== (int) $empresaId) {
                    $validator->errors()->add('agencia_id', 'La agencia no pertenece a la empresa indicada.');
                }
            }

            if ($role === 'asesor' && $this->filled('supervisor_id')) {
                $supervisor = User::find($this->input('supervisor_id'));

                if (! $supervisor || ! $supervisor->hasRole('supervisor')) {
                    $validator->errors()->add('supervisor_id', 'El usuario indicado no tiene el rol de supervisor.');
                } elseif ($agenciaId && $supervisor->agencia_id !== (int) $agenciaId) {
                    $validator->errors()->add('supervisor_id', 'El supervisor debe pertenecer a la misma agencia.');
                }
            }
        });
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
            'email.required' => 'El email es requerido',
            'email.unique' => 'Ya existe un usuario con este email',
            'password.required' => 'La contraseña es requerida',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'role.required' => 'El rol es requerido',
            'role.in' => 'No tienes permiso para asignar este rol',
            'empresa_id.required' => 'La empresa es requerida',
            'agencia_id.required' => 'La agencia es requerida para este rol',
            'supervisor_id.required' => 'El supervisor es requerido para el rol asesor',
        ];
    }
}
