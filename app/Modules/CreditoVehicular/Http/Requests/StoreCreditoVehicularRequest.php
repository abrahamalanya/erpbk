<?php

namespace App\Modules\CreditoVehicular\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditoVehicularRequest extends FormRequest
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
            'vehiculo_ids' => ['required', 'array', 'min:1'],
            'vehiculo_ids.*' => ['integer', 'distinct', 'exists:vehiculos,id'],
            'supervisado_por' => ['required', 'integer', 'exists:users,id'],
            'monto_prestamo' => ['required', 'numeric', 'min:0.01'],
            'interes' => ['nullable', 'numeric', 'min:0'],
            'tipo_cuota' => ['required', Rule::in(['diario', 'semanal', 'quincenal', 'mensual'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vehiculo_ids.required' => 'Debes seleccionar al menos un vehículo',
            'vehiculo_ids.min' => 'Debes seleccionar al menos un vehículo',
            'vehiculo_ids.*.exists' => 'Uno de los vehículos indicados no existe',
            'vehiculo_ids.*.distinct' => 'No repitas el mismo vehículo',
            'supervisado_por.required' => 'Debes indicar el usuario que supervisa el crédito',
            'monto_prestamo.required' => 'El monto del préstamo es requerido',
            'tipo_cuota.required' => 'El tipo de cuota es requerido',
            'tipo_cuota.in' => 'El tipo de cuota no es válido',
        ];
    }
}
