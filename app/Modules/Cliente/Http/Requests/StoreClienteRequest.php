<?php

namespace App\Modules\Cliente\Http\Requests;

use App\Modules\Cliente\Models\ClienteFoto;
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
            'fecha_nacimiento' => ['nullable', 'date'],
            'sexo' => ['nullable', Rule::in(['m', 'f'])],
            'estado_civil' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'ubigeo_distrito_id' => ['nullable', 'integer', 'exists:ubigeo_distritos,id'],
            'referencia' => ['nullable', 'string', 'max:500'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'direccion_negocio' => ['nullable', 'string', 'max:255'],
            'ubigeo_distrito_negocio_id' => ['nullable', 'integer', 'exists:ubigeo_distritos,id'],
            'referencia_negocio' => ['nullable', 'string', 'max:500'],
            'latitud_negocio' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud_negocio' => ['nullable', 'numeric', 'between:-180,180'],
            'empresa_id' => [
                Rule::requiredIf(fn (): bool => $actor->hasRole('sistemas')),
                'nullable', 'integer', 'exists:empresas,id',
            ],
            'agencia_id' => [
                Rule::requiredIf(fn (): bool => $actor->hasAnyRole(['administrador_general', 'secretaria', 'sistemas'])),
                'nullable', 'integer', 'exists:agencias,id',
            ],
            'foto_cliente' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'foto_dni' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'foto_dni_reverso' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'foto_suministro' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'foto_recibo_luz' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'fotos_casa' => ['nullable', 'array', 'max:'.ClienteFoto::MAX_POR_TIPO],
            'fotos_casa.*' => ['image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'fotos_negocio' => ['nullable', 'array', 'max:'.ClienteFoto::MAX_POR_TIPO],
            'fotos_negocio.*' => ['image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'fotos_adicionales' => ['nullable', 'array', 'max:'.ClienteFoto::MAX_POR_TIPO],
            'fotos_adicionales.*' => ['image', 'max:8192', 'mimes:jpg,jpeg,png'],
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
            'foto_dni_reverso.image' => 'La foto del reverso del DNI debe ser una imagen',
            'foto_suministro.image' => 'La foto del suministro debe ser una imagen',
            'foto_recibo_luz.image' => 'La foto del recibo de luz debe ser una imagen',
            'fotos_casa.max' => 'No puedes subir más de '.ClienteFoto::MAX_POR_TIPO.' fotos de la casa',
            'fotos_negocio.max' => 'No puedes subir más de '.ClienteFoto::MAX_POR_TIPO.' fotos del negocio',
            'fotos_adicionales.max' => 'No puedes subir más de '.ClienteFoto::MAX_POR_TIPO.' fotos adicionales',
            'fotos_casa.*.image' => 'Cada foto de la casa debe ser una imagen',
            'fotos_negocio.*.image' => 'Cada foto del negocio debe ser una imagen',
            'fotos_adicionales.*.image' => 'Cada foto adicional debe ser una imagen',
        ];
    }
}
