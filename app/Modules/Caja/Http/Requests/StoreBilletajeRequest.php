<?php

namespace App\Modules\Caja\Http\Requests;

use App\Modules\Caja\Models\Caja;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Usuario\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
     * Un administrador (sistemas/administrador_general/administrador_agencia)
     * envía el billetaje a un asesor y define aquí todo el egreso: medio,
     * canal, cuenta bancaria y vaucher. El asesor solo aceptará o rechazará.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'asesor_id' => ['required', 'integer', 'exists:users,id'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'motivo' => ['required', 'string', 'max:500'],
            'medio_egreso' => ['sometimes', 'string', 'in:efectivo,cuenta_bancaria'],
            'canal_egreso' => ['required_if:medio_egreso,cuenta_bancaria', 'nullable', 'string', 'in:transferencia,yape,plin,deposito'],
            'cuenta_bancaria_id' => ['required_if:medio_egreso,cuenta_bancaria', 'nullable', 'integer', 'exists:cuentas_bancarias,id'],
            'comprobante' => [
                Rule::requiredIf($this->input('medio_egreso', 'efectivo') === 'cuenta_bancaria'),
                'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192',
            ],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
        ];
    }

    /**
     * The destinatario must be an asesor within the actor's scope with an open
     * caja, and the optional cliente must belong to that asesor's agencia.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $actor = $this->user();
            $asesor = User::query()->find($this->input('asesor_id'));

            if (! $asesor) {
                $validator->errors()->add('asesor_id', 'El asesor indicado no existe o no pertenece a tu empresa.');

                return;
            }

            if (! $asesor->hasRole('asesor')) {
                $validator->errors()->add('asesor_id', 'Solo puedes enviar billetaje a un asesor.');
            }

            if (! $actor->hasRole('sistemas')) {
                if ($actor->empresa_id !== $asesor->empresa_id) {
                    $validator->errors()->add('asesor_id', 'El asesor no pertenece a tu empresa.');
                } elseif ($actor->hasRole('administrador_agencia') && $actor->agencia_id !== $asesor->agencia_id) {
                    $validator->errors()->add('asesor_id', 'El asesor no pertenece a tu agencia.');
                }
            }

            $tieneCajaAbierta = Caja::query()->where('user_id', $asesor->id)->whereHas('cicloAbierto')->exists();

            if (! $tieneCajaAbierta) {
                $validator->errors()->add('asesor_id', 'El asesor no tiene su caja aperturada.');
            }

            $cliente = Cliente::find($this->input('cliente_id'));

            if (! $cliente) {
                return;
            }

            if ($actor->hasRole('sistemas')) {
                return;
            }

            if ($actor->empresa_id !== $cliente->empresa_id) {
                $validator->errors()->add('cliente_id', 'El cliente no pertenece a tu empresa.');

                return;
            }

            if ($asesor->agencia_id !== null && $asesor->agencia_id !== $cliente->agencia_id) {
                $validator->errors()->add('cliente_id', 'El cliente no pertenece a la agencia del asesor.');
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
            'asesor_id.required' => 'Debes indicar el asesor que recibirá el billetaje',
            'asesor_id.exists' => 'El asesor indicado no existe',
            'monto.required' => 'El monto es requerido',
            'monto.numeric' => 'El monto debe ser un número',
            'monto.min' => 'El monto debe ser mayor a cero',
            'motivo.required' => 'Debes indicar el motivo del billetaje',
            'medio_egreso.in' => 'El medio de egreso debe ser efectivo o cuenta bancaria',
            'canal_egreso.required_if' => 'Debes indicar el canal (transferencia, yape, plin o depósito)',
            'cuenta_bancaria_id.required_if' => 'Debes seleccionar la cuenta bancaria de la que sale el dinero',
            'comprobante.required' => 'Debes subir el vaucher de la transferencia para enviar este billetaje',
            'cliente_id.exists' => 'El cliente indicado no existe',
        ];
    }
}
