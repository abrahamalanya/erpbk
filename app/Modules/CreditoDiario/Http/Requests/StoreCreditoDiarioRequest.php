<?php

namespace App\Modules\CreditoDiario\Http\Requests;

use App\Modules\Credito\Http\Requests\Concerns\ValidaNumeroCuotas;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditoDiarioRequest extends FormRequest
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
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'monto_prestamo' => ['required', 'numeric', 'min:0.01'],
            'interes' => ['nullable', 'numeric', 'min:0'],
            'interes_solicitud_especial' => ['sometimes', 'boolean'],
            'motivo_interes' => ['nullable', 'string', 'max:255'],
            'tipo_cuota' => ['required', Rule::in(['diario', 'semanal', 'quincenal', 'mensual'])],
            'numero_cuotas' => $this->reglasNumeroCuotas('diario'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cliente_id.required' => 'Debes seleccionar un cliente',
            'cliente_id.exists' => 'El cliente indicado no existe',
            'monto_prestamo.required' => 'El monto del préstamo es requerido',
            'tipo_cuota.required' => 'El tipo de cuota es requerido',
            'tipo_cuota.in' => 'El tipo de cuota no es válido',
        ];
    }
}
