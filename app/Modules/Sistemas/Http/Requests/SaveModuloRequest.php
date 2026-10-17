<?php

namespace App\Modules\Sistemas\Http\Requests;

use App\Modules\Sistemas\Models\Modulo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveModuloRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage', Modulo::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return ['revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/']];
        }

        return [
            'key' => $this->isMethod('POST')
                ? ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:modulos,key']
                : ['prohibited'],
            'nombre' => ['required', 'string', 'max:255'],
            'grupo' => ['nullable', 'string', 'max:255'],
            'revision' => $this->isMethod('POST') ? ['prohibited'] : ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.required' => 'Indica la clave técnica del módulo.',
            'key.regex' => 'La clave debe empezar con una letra minúscula y contener solo letras minúsculas, números o guion bajo.',
            'key.unique' => 'Esta clave ya está registrada.',
            'key.prohibited' => 'La clave técnica no se puede modificar.',
            'nombre.required' => 'Indica el nombre del módulo.',
            'revision.required' => 'Recarga el módulo antes de guardar o eliminar.',
            'revision.size' => 'La revisión del módulo no es válida. Recarga sus datos.',
        ];
    }
}
