<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ActualizarFechaDesembolsoCreditoRequest extends FormRequest
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
            'fecha_desembolso' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_desembolso.required' => 'La fecha de desembolso es requerida',
            'fecha_desembolso.date' => 'La fecha de desembolso no es válida',
            'fecha_desembolso.before_or_equal' => 'La fecha de desembolso no puede ser futura',
        ];
    }
}
