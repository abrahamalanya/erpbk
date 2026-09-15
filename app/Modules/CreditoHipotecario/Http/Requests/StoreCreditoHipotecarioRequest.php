<?php

namespace App\Modules\CreditoHipotecario\Http\Requests;

use App\Modules\Credito\Http\Requests\Concerns\ValidaNumeroCuotas;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditoHipotecarioRequest extends FormRequest
{
    use ValidaNumeroCuotas;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'inmueble_ids' => ['required', 'array', 'min:1'],
            'inmueble_ids.*' => ['integer', 'distinct', 'exists:inmuebles,id'],
            'supervisado_por' => ['required', 'integer', 'exists:users,id'],
            'aval_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'aval_2_id' => ['nullable', 'integer', 'exists:clientes,id', 'different:aval_id'],
            'monto_prestamo' => ['required', 'numeric', 'min:0.01'],
            'interes' => ['nullable', 'numeric', 'min:0'],
            'interes_solicitud_especial' => ['sometimes', 'boolean'],
            'motivo_interes' => ['nullable', 'string', 'max:255'],
            'tipo_cuota' => ['required', Rule::in(['diario', 'semanal', 'quincenal', 'mensual'])],
            // Un crédito de interés compuesto (sistema francés) necesita un
            // número de cuotas definido — la cuota fija se calcula sobre él.
            'numero_cuotas' => [
                ...$this->reglasNumeroCuotas('hipotecario'),
                Rule::requiredIf(fn (): bool => $this->input('tipo_interes') === 'compuesto'),
            ],
            'tipo_interes' => ['nullable', Rule::in(['simple', 'compuesto'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'inmueble_ids.required' => 'Debes seleccionar al menos un inmueble',
            'inmueble_ids.min' => 'Debes seleccionar al menos un inmueble',
            'inmueble_ids.*.exists' => 'Uno de los inmuebles indicados no existe',
            'inmueble_ids.*.distinct' => 'No repitas el mismo inmueble',
            'supervisado_por.required' => 'Debes indicar el usuario que supervisa el crédito',
            'monto_prestamo.required' => 'El monto del préstamo es requerido',
            'tipo_cuota.required' => 'El tipo de cuota es requerido',
            'tipo_cuota.in' => 'El tipo de cuota no es válido',
            'numero_cuotas.required_if' => 'Debes indicar el número de cuotas para un crédito de interés compuesto',
            'tipo_interes.in' => 'El tipo de interés no es válido',
        ];
    }
}
