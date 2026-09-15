<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class VenderCreditoRequest extends FormRequest
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
     * `pagos_previos` son depósitos que el comprador ya hizo antes de la
     * firma (opcional); el saldo restante se cancela a la firma.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'comprador_nombre' => ['required', 'string', 'max:255'],
            'comprador_tipo_documento' => ['required', Rule::in(['dni', 'ce', 'pasaporte'])],
            'comprador_numero_documento' => ['required', 'string', 'max:20'],
            'comprador_domicilio' => ['nullable', 'string', 'max:255'],
            'precio_transferencia' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'pagos_previos' => ['nullable', 'array'],
            'pagos_previos.*.monto' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'pagos_previos.*.fecha' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $precio = (float) $this->input('precio_transferencia', 0);
            $pagado = collect($this->input('pagos_previos', []))->sum(fn (array $pago) => (float) ($pago['monto'] ?? 0));

            if ($pagado > $precio) {
                $validator->errors()->add('pagos_previos', 'La suma de los depósitos previos no puede superar el precio de transferencia.');
            }
        });
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comprador_nombre.required' => 'El nombre del comprador es requerido',
            'comprador_tipo_documento.required' => 'El tipo de documento del comprador es requerido',
            'comprador_tipo_documento.in' => 'El tipo de documento del comprador no es válido',
            'comprador_numero_documento.required' => 'El número de documento del comprador es requerido',
            'precio_transferencia.required' => 'El precio de transferencia es requerido',
            'precio_transferencia.numeric' => 'El precio de transferencia debe ser un número',
            'precio_transferencia.min' => 'El precio de transferencia debe ser mayor a cero',
            'pagos_previos.*.monto.required' => 'El monto del depósito es requerido',
            'pagos_previos.*.fecha.required' => 'La fecha del depósito es requerida',
            'pagos_previos.*.fecha.before_or_equal' => 'La fecha del depósito no puede ser futura',
        ];
    }
}
