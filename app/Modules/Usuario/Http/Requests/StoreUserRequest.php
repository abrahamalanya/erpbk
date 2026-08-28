<?php

namespace App\Modules\Usuario\Http\Requests;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
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
        $actor = $this->user();
        $hierarchy = app(UserHierarchyService::class);
        $roles = $this->rolesInput();

        $agenciaLevel = (bool) array_intersect($roles, ['administrador_agencia', 'peinadora', 'supervisor', 'asesor']);
        $needsSupervisor = in_array('asesor', $roles, true) && ! in_array('supervisor', $roles, true);

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['required', 'string', 'max:255'],
            'dni' => ['required', 'string', 'regex:/^\d{8}$/', 'unique:users,dni'],
            'usuario' => ['nullable', 'string', 'max:50', 'alpha_dash'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'estado' => ['sometimes', Rule::in(['activo', 'inactivo'])],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($hierarchy->assignableRoles($actor))],
            'empresa_id' => [
                Rule::requiredIf(fn (): bool => $actor->hasRole('sistemas')),
                'nullable', 'integer', 'exists:empresas,id',
            ],
            'agencia_id' => [
                Rule::requiredIf(fn (): bool => $agenciaLevel && ! $actor->hasRole('administrador_agencia')),
                'nullable', 'integer', 'exists:agencias,id',
            ],
            'supervisor_id' => [
                Rule::requiredIf(fn (): bool => $needsSupervisor),
                'nullable', 'integer', 'exists:users,id',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function rolesInput(): array
    {
        return array_values(array_filter(
            (array) $this->input('roles', []),
            'is_string',
        ));
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->user();
            $roles = $this->rolesInput();
            $empresaId = $actor->hasRole('sistemas') ? $this->input('empresa_id') : $actor->empresa_id;

            if (! $this->filled('email') && ! Empresa::find($empresaId)?->prefijo) {
                $validator->errors()->add('email', 'El email es requerido: la empresa no tiene un prefijo de correo configurado.');
            }

            if ($roles === []) {
                return;
            }

            $agenciaLevel = (bool) array_intersect($roles, ['administrador_agencia', 'peinadora', 'supervisor', 'asesor']);
            $agenciaId = $actor->hasRole('administrador_agencia') ? $actor->agencia_id : $this->input('agencia_id');

            if ($agenciaLevel && $agenciaId) {
                $agencia = Agencia::find($agenciaId);

                if ($agencia && $empresaId && (int) $agencia->empresa_id !== (int) $empresaId) {
                    $validator->errors()->add('agencia_id', 'La agencia no pertenece a la empresa indicada.');
                }
            }

            if (in_array('asesor', $roles, true) && $this->filled('supervisor_id')) {
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
            'dni.required' => 'El DNI es requerido',
            'dni.regex' => 'El DNI debe tener 8 dígitos',
            'dni.unique' => 'Ya existe un usuario con este DNI',
            'email.unique' => 'Ya existe un usuario con este email',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'roles.required' => 'El rol es requerido',
            'roles.min' => 'El rol es requerido',
            'roles.*.in' => 'No tienes permiso para asignar este rol',
            'empresa_id.required' => 'La empresa es requerida',
            'agencia_id.required' => 'La agencia es requerida para este rol',
            'supervisor_id.required' => 'El supervisor es requerido para el rol asesor',
        ];
    }
}
