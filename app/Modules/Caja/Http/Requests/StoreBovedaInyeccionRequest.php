<?php

namespace App\Modules\Caja\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBovedaInyeccionRequest extends FormRequest
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
     * cuenta_bancaria_origen_id only applies to a traspaso (the target bóveda
     * is an agencia one) — the principal's own inyectar() with medio
     * cuenta_bancaria is external capital landing directly in one of its own
     * cuentas, with no "origen" to pick from.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'monto' => ['required', 'numeric', 'min:0.01'],
            'concepto' => ['nullable', 'string', 'max:255'],
            'medio' => ['nullable', 'string', 'in:efectivo,cuenta_bancaria'],
            'cuenta_bancaria_id' => ['required_if:medio,cuenta_bancaria', 'integer'],
            'cuenta_bancaria_origen_id' => [
                Rule::requiredIf(fn (): bool => $this->input('medio') === 'cuenta_bancaria' && $this->route('boveda')?->tipo === 'agencia'),
                'nullable',
                'integer',
            ],
            'comprobante' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
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
            'monto.required' => 'El monto es requerido',
            'monto.numeric' => 'El monto debe ser un número',
            'monto.min' => 'El monto debe ser mayor a cero',
            'medio.in' => 'El medio debe ser efectivo o cuenta_bancaria',
            'cuenta_bancaria_id.required_if' => 'Debes seleccionar la cuenta bancaria que recibirá el dinero',
            'cuenta_bancaria_origen_id.required' => 'Debes seleccionar la cuenta bancaria de la que saldrá el dinero',
        ];
    }
}
