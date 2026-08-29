<?php

namespace App\Modules\Usuario\Http\Requests;

use App\Modules\Usuario\Models\User;
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
        $actor = $this->user();
        $hierarchy = app(UserHierarchyService::class);
        $target = $this->route('user');

        $effectiveRoles = $this->effectiveRoles($target);
        $agenciaRequerida = $hierarchy->includesAgenciaLevelRole($effectiveRoles)
            && ! $actor->hasRole('administrador_agencia')
            && $target->agencia_id === null;

        // On edit the actor may keep roles the user already has — even ones
        // above the actor's own assignment ceiling — but still can't grant a
        // brand-new role beyond it.
        $rolesPermitidos = array_values(array_unique(array_merge(
            $hierarchy->assignableRoles($actor),
            $target->getRoleNames()->all(),
        )));

        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:255'],
            'apellido' => ['sometimes', 'required', 'string', 'max:255'],
            'dni' => [
                'sometimes', 'required', 'string', 'regex:/^\d{8}$/',
                Rule::unique('users', 'dni')->ignore($target),
            ],
            'telefono' => ['nullable', 'string', 'max:20'],
            'estado' => ['sometimes', Rule::in(['activo', 'inactivo'])],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($rolesPermitidos)],
            'agencia_id' => [
                $agenciaRequerida ? 'required' : 'nullable',
                'integer',
                // The user's empresa is fixed, so the agencia must belong to it.
                Rule::exists('agencias', 'id')->where('empresa_id', $target->empresa_id),
            ],
        ];
    }

    /**
     * Roles the user will end up with: the submitted set when present,
     * otherwise the ones already assigned.
     *
     * @return list<string>
     */
    private function effectiveRoles(User $target): array
    {
        if ($this->has('roles')) {
            return array_values(array_filter((array) $this->input('roles', []), 'is_string'));
        }

        return $target->getRoleNames()->all();
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
            'roles.*.in' => 'No tienes permiso para asignar este rol',
            'agencia_id.required' => 'La agencia es requerida para este rol',
            'agencia_id.exists' => 'La agencia no pertenece a la empresa del usuario',
        ];
    }
}
