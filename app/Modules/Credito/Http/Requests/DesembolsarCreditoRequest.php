<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DesembolsarCreditoRequest extends FormRequest
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
            'numero_cuotas' => ['nullable', 'integer', 'min:1'],
            'interes' => ['nullable', 'numeric', 'min:0'],
            'fecha_desembolso' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_desembolso.date' => 'La fecha de desembolso no es válida',
            'fecha_desembolso.before_or_equal' => 'La fecha de desembolso no puede ser futura',
        ];
    }
}
