<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConfiguracionCreditoRequest extends FormRequest
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
            'empresa_id' => [
                Rule::requiredIf(fn (): bool => $this->user()->hasRole('sistemas')),
                'nullable', 'integer', 'exists:empresas,id',
            ],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
            'tipo_credito' => ['nullable', Rule::in(['prendario', 'vehicular', 'hipotecario'])],
            'interes_default' => ['required', 'numeric', 'min:0'],
            'plazo_dias' => ['required', 'integer', 'min:1'],
            'dias_espera_mora' => ['required', 'integer', 'min:0'],
            'dias_minimo_interes' => ['required', 'integer', 'min:0'],
            'tasa_mora_diaria' => ['required', 'numeric', 'min:0'],
            'max_refrendos' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
