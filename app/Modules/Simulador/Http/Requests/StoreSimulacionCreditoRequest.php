<?php

namespace App\Modules\Simulador\Http\Requests;

use App\Modules\Cliente\Models\Cliente;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class StoreSimulacionCreditoRequest extends CalcularSimulacionCreditoRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'cliente_id' => ['nullable', 'integer', 'exists:clientes,id']];
    }

    /**
     * Confirma que el cliente pertenece al mismo tenant del actor — mismo
     * chequeo que StoreBienRequest.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('cliente_id') === null) {
                return;
            }
            $cliente = Cliente::find($this->input('cliente_id'));

            if (! $cliente) {
                return;
            }

            $actor = $this->user();

            if ($actor->hasRole('sistemas')) {
                return;
            }

            if ($actor->empresa_id !== $cliente->empresa_id) {
                $validator->errors()->add('cliente_id', 'El cliente no pertenece a tu empresa.');

                return;
            }

            if ($actor->agencia_id !== null && $actor->agencia_id !== $cliente->agencia_id) {
                $validator->errors()->add('cliente_id', 'El cliente no pertenece a tu agencia.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'tipo_credito.required' => 'El tipo de crédito es requerido',
            'tipo_credito.in' => 'El tipo de crédito no es válido',
            'cliente_id.exists' => 'El cliente indicado no existe',
            'monto_prestamo.required' => 'El monto del préstamo es requerido',
            'tipo_cuota.required' => 'El tipo de cuota es requerido',
            'tipo_cuota.in' => 'El tipo de cuota no es válido',
        ];
    }
}
