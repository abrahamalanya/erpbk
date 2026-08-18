<?php

namespace App\Modules\Cliente\Http\Requests;

use App\Modules\Empresa\Models\Agencia;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClienteRequest extends FormRequest
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

        return [
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['required', 'string', 'max:255'],
            'tipo_documento' => ['required', Rule::in(['dni', 'ce', 'pasaporte'])],
            'numero_documento' => [
                'required', 'string', 'max:20',
                Rule::unique('clientes', 'numero_documento')->where(
                    fn ($query) => $query->where('empresa_id', $actor->hasRole('sistemas') ? $this->input('empresa_id') : $actor->empresa_id)
                ),
            ],
            'telefono' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:500'],
            'empresa_id' => [
                Rule::requiredIf(fn (): bool => $actor->hasRole('sistemas')),
                'nullable', 'integer', 'exists:empresas,id',
            ],
            'agencia_id' => [
                Rule::requiredIf(fn (): bool => $actor->hasAnyRole(['administrador_general', 'secretaria', 'sistemas'])),
                'nullable', 'integer', 'exists:agencias,id',
            ],
            'foto_cliente' => ['nullable', 'image', 'max:4096', 'mimes:jpg,jpeg,png'],
            'foto_dni' => ['nullable', 'image', 'max:4096', 'mimes:jpg,jpeg,png'],
            'foto_casa' => ['nullable', 'image', 'max:4096', 'mimes:jpg,jpeg,png'],
            'foto_negocio' => ['nullable', 'image', 'max:4096', 'mimes:jpg,jpeg,png'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->user();
            $agenciaId = $actor->hasAnyRole(['administrador_general', 'secretaria']) ? $this->input('agencia_id') : $actor->agencia_id;
            $empresaId = $actor->hasRole('sistemas') ? $this->input('empresa_id') : $actor->empresa_id;

            if ($agenciaId && $empresaId) {
                $agencia = Agencia::find($agenciaId);

                if ($agencia && (int) $agencia->empresa_id !== (int) $empresaId) {
                    $validator->errors()->add('agencia_id', 'La agencia no pertenece a la empresa indicada.');
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
            'tipo_documento.required' => 'El tipo de documento es requerido',
            'tipo_documento.in' => 'El tipo de documento no es válido',
            'numero_documento.required' => 'El número de documento es requerido',
            'numero_documento.unique' => 'Ya existe un cliente con este número de documento',
            'empresa_id.required' => 'La empresa es requerida',
            'agencia_id.required' => 'La agencia es requerida',
            'foto_cliente.image' => 'La foto del cliente debe ser una imagen',
            'foto_dni.image' => 'La foto del DNI debe ser una imagen',
            'foto_casa.image' => 'La foto de la casa debe ser una imagen',
            'foto_negocio.image' => 'La foto del negocio debe ser una imagen',
        ];
    }
}
