<?php

namespace App\Modules\Simulador\Http\Requests;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Services\ConfiguracionCreditoService;
use Closure;
use DomainException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSimulacionCreditoRequest extends FormRequest
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
        return [
            'tipo_credito' => ['required', Rule::in(['prendario', 'vehicular', 'hipotecario', 'diario'])],
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'monto_prestamo' => ['required', 'numeric', 'min:0.01'],
            'interes' => ['nullable', 'numeric', 'min:0'],
            'tipo_cuota' => ['required', Rule::in(['diario', 'semanal', 'quincenal', 'mensual'])],
            'numero_cuotas' => [
                'nullable', 'integer', 'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $agencia = $this->user()?->agencia;
                    $tipoCredito = $this->input('tipo_credito');

                    if ($agencia === null || ! is_string($tipoCredito)) {
                        return;
                    }

                    try {
                        $max = app(ConfiguracionCreditoService::class)->resolverPara($agencia, $tipoCredito)->max_cuotas;
                    } catch (DomainException) {
                        return;
                    }

                    if ((int) $value > $max) {
                        $fail("El número de cuotas no puede superar {$max} para este tipo de crédito.");
                    }
                },
            ],
        ];
    }

    /**
     * Confirma que el cliente pertenece al mismo tenant del actor — mismo
     * chequeo que StoreBienRequest.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
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
            'tipo_credito.required' => 'El tipo de crédito es requerido',
            'tipo_credito.in' => 'El tipo de crédito no es válido',
            'cliente_id.required' => 'El cliente es requerido',
            'cliente_id.exists' => 'El cliente indicado no existe',
            'monto_prestamo.required' => 'El monto del préstamo es requerido',
            'tipo_cuota.required' => 'El tipo de cuota es requerido',
            'tipo_cuota.in' => 'El tipo de cuota no es válido',
        ];
    }
}
