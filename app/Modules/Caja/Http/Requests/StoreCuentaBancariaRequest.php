<?php

namespace App\Modules\Caja\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCuentaBancariaRequest extends FormRequest
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
            'banco_id' => ['required', 'integer', 'exists:bancos,id'],
            'numero_cuenta' => ['required', 'string', 'max:50'],
            'titular' => ['required', 'string', 'max:255'],
            'tipo_cuenta' => ['nullable', 'string', 'in:ahorro,corriente'],
            'moneda' => ['nullable', 'string', 'in:PEN,USD'],
            'alias' => ['nullable', 'string', 'max:255'],
            'saldo_inicial' => ['nullable', 'numeric', 'min:0'],
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
            'banco_id.required' => 'El banco es requerido',
            'banco_id.exists' => 'El banco seleccionado no existe',
            'numero_cuenta.required' => 'El número de cuenta es requerido',
            'titular.required' => 'El titular es requerido',
            'tipo_cuenta.in' => 'El tipo de cuenta debe ser ahorro o corriente',
            'moneda.in' => 'La moneda debe ser PEN o USD',
            'saldo_inicial.numeric' => 'El saldo inicial debe ser un número',
            'saldo_inicial.min' => 'El saldo inicial no puede ser negativo',
            'numero_yape.required_if' => 'Debes indicar el número de Yape',
            'numero_plin.required_if' => 'Debes indicar el número de Plin',
        ];
    }
}
