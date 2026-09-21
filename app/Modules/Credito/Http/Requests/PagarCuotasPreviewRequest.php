<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PagarCuotasPreviewRequest extends FormRequest
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
            'numero_cuotas' => ['required_without:monto_pagado', 'nullable', 'integer', 'min:1'],
            'monto_pagado' => ['required_without:numero_cuotas', 'nullable', 'numeric', 'min:0.01'],
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
            'numero_cuotas.required_without' => 'Indica el número de cuotas o el monto a pagar',
            'numero_cuotas.min' => 'Debes pagar al menos 1 cuota',
            'monto_pagado.required_without' => 'Indica el número de cuotas o el monto a pagar',
            'monto_pagado.min' => 'El monto pagado debe ser mayor a cero',
        ];
    }
}
