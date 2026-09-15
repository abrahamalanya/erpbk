<?php

namespace App\Modules\Ruta\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReordenarRutaCobranzaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cliente_ids' => ['required', 'array', 'min:1'],
            'cliente_ids.*' => ['integer', 'distinct', 'exists:clientes,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cliente_ids.required' => 'Debes indicar el nuevo orden de los clientes',
            'cliente_ids.*.distinct' => 'No repitas el mismo cliente',
            'cliente_ids.*.exists' => 'Uno de los clientes indicados no existe',
        ];
    }
}
