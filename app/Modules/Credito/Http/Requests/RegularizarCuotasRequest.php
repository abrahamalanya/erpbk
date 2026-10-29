<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegularizarCuotasRequest extends FormRequest
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
            'numero_cuotas' => ['required_without:monto', 'prohibits:monto', 'nullable', 'integer', 'min:1'],
            'monto' => ['required_without:numero_cuotas', 'prohibits:numero_cuotas', 'nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'motivo' => ['required', 'string', 'max:255'],
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
            'numero_cuotas.required' => 'Debes indicar cuántas cuotas regularizar',
            'numero_cuotas.min' => 'Debes regularizar al menos 1 cuota',
            'motivo.required' => 'Debes indicar el motivo de la regularización',
        ];
    }
}
