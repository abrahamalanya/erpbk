<?php

namespace App\Modules\Ruta\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MostrarRutaCobranzaRequest extends FormRequest
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
            'asesor_id' => ['nullable', 'integer'],
            'tipo_credito' => ['nullable', 'string', 'in:diario,prendario,hipotecario,vehicular'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_credito.in' => 'El tipo de crédito debe ser diario, prendario, hipotecario o vehicular',
        ];
    }
}
