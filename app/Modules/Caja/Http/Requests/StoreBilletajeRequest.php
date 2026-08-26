<?php

namespace App\Modules\Caja\Http\Requests;

use App\Modules\Cliente\Models\Cliente;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBilletajeRequest extends FormRequest
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
            'monto' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['required', 'string', 'max:500'],
            'medio_recepcion' => ['required', 'string', 'in:efectivo,yape,plin,transferencia'],
            'datos_recepcion' => ['required_unless:medio_recepcion,efectivo', 'nullable', 'string', 'max:255'],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
        ];
    }

    /**
     * Confirms the cliente is within the actor's own tenant scope — mirrors
     * the same cross-entity ownership check StoreBienRequest does.
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
            'motivo.required' => 'Debes indicar el motivo del billetaje',
            'medio_recepcion.required' => 'Debes indicar por qué medio quieres recibir el dinero',
            'medio_recepcion.in' => 'El medio de recepción debe ser efectivo, yape, plin o transferencia',
            'datos_recepcion.required_unless' => 'Debes indicar a dónde enviar el dinero (número o cuenta)',
            'cliente_id.exists' => 'El cliente indicado no existe',
        ];
    }
}
