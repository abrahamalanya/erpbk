<?php

namespace App\Modules\Planilla\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePlanillaRequest extends FormRequest
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
            'periodo' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'fecha_pago' => ['required', 'date'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'periodo.required' => 'El periodo es requerido',
            'periodo.regex' => 'El periodo debe tener el formato AAAA-MM',
            'fecha_pago.required' => 'La fecha de pago es requerida',
        ];
    }
}
