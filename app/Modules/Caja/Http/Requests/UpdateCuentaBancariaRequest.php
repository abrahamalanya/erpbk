<?php

namespace App\Modules\Caja\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCuentaBancariaRequest extends FormRequest
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
            'banco_id' => ['sometimes', 'integer', 'exists:bancos,id'],
            'numero_cuenta' => ['sometimes', 'string', 'max:50'],
            'titular' => ['sometimes', 'string', 'max:255'],
            'tipo_cuenta' => ['nullable', 'string', 'in:ahorro,corriente'],
            'moneda' => ['sometimes', 'string', 'in:PEN,USD'],
            'alias' => ['nullable', 'string', 'max:255'],
            'activa' => ['sometimes', 'boolean'],
            'acepta_yape' => ['sometimes', 'boolean'],
            'numero_yape' => ['required_if:acepta_yape,true', 'nullable', 'string', 'max:20'],
            'acepta_plin' => ['sometimes', 'boolean'],
            'numero_plin' => ['required_if:acepta_plin,true', 'nullable', 'string', 'max:20'],
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
            'banco_id.exists' => 'El banco seleccionado no existe',
            'tipo_cuenta.in' => 'El tipo de cuenta debe ser ahorro o corriente',
            'moneda.in' => 'La moneda debe ser PEN o USD',
            'numero_yape.required_if' => 'Debes indicar el número de Yape',
            'numero_plin.required_if' => 'Debes indicar el número de Plin',
        ];
    }
}
