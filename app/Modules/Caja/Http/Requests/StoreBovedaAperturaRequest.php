<?php

namespace App\Modules\Caja\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBovedaAperturaRequest extends FormRequest
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
            'saldo_inicial' => ['nullable', 'numeric', 'min:0.01'],
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
            'saldo_inicial.numeric' => 'El saldo inicial debe ser un número',
            'saldo_inicial.min' => 'El saldo inicial debe ser mayor a cero',
        ];
    }
}
