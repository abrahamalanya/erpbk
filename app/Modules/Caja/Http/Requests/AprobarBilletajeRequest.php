<?php

namespace App\Modules\Caja\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AprobarBilletajeRequest extends FormRequest
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
     * medio_egreso is optional and defaults to 'efectivo' in
     * BilletajeService::aprobar() — the historical (and still most common)
     * way a billetaje is funded, so approving without a body keeps working.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'medio_egreso' => ['sometimes', 'string', 'in:efectivo,cuenta_bancaria'],
            'canal_egreso' => ['required_if:medio_egreso,cuenta_bancaria', 'nullable', 'string', 'in:transferencia,yape,plin,deposito'],
            'cuenta_bancaria_id' => ['required_if:medio_egreso,cuenta_bancaria', 'nullable', 'integer', 'exists:cuentas_bancarias,id'],
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
            'medio_egreso.in' => 'El medio de egreso debe ser efectivo o cuenta bancaria',
            'canal_egreso.required_if' => 'Debes indicar el canal (transferencia, yape, plin o depósito)',
            'cuenta_bancaria_id.required_if' => 'Debes seleccionar la cuenta bancaria de la que sale el dinero',
        ];
    }
}
