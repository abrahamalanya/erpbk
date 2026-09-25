<?php

namespace App\Modules\Caja\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListarMovimientosCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `solo_desembolsos` y `excluir_desembolsos` dividen los egresos según
     * su origen real (`credito_id`). Son excluyentes entre sí y con
     * `concepto_id`.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', 'in:ingreso,egreso'],
            'concepto_id' => [
                'nullable',
                'integer',
                'exists:conceptos,id',
                'prohibits:solo_desembolsos',
                'prohibits:excluir_desembolsos',
            ],
            'solo_desembolsos' => [
                'nullable',
                'boolean',
                'prohibited_if:tipo,ingreso',
                'prohibits:excluir_desembolsos',
            ],
            'excluir_desembolsos' => ['nullable', 'boolean', 'prohibited_if:tipo,ingreso'],
            'registrado_por' => ['nullable', 'integer', 'exists:users,id'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Indica si quieres ingresos o egresos',
            'tipo.in' => 'El tipo debe ser ingreso o egreso',
            'concepto_id.exists' => 'El concepto indicado no existe',
            'concepto_id.prohibits' => 'No puedes filtrar por concepto y por desembolsos a la vez',
            'solo_desembolsos.prohibited_if' => 'Los desembolsos solo existen entre los egresos',
            'solo_desembolsos.prohibits' => 'No puedes filtrar por desembolsos y por egresos manuales a la vez',
            'excluir_desembolsos.prohibited_if' => 'La exclusión de desembolsos solo aplica a los egresos',
            'registrado_por.exists' => 'El usuario indicado no existe',
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial',
        ];
    }
}
