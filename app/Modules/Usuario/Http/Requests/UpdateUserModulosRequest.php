<?php

namespace App\Modules\Usuario\Http\Requests;

use App\Modules\Sistemas\Services\ModuloService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserModulosRequest extends FormRequest
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
            'modulos' => ['present', 'nullable', 'array'],
            'modulos.*' => ['string', Rule::exists('modulos', 'key')],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $target = $this->route('user');

            if (! $target->hasAnyRole(ModuloService::ROLES_RESTRINGIBLES)) {
                $validator->errors()->add('modulos', 'Este usuario no tiene un rol gestionado por módulos.');
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
            'modulos.present' => 'Debe indicar el campo de módulos (un arreglo, vacío, o null para quitar la restricción)',
            'modulos.*.in' => 'Uno de los módulos indicados no existe',
        ];
    }
}
