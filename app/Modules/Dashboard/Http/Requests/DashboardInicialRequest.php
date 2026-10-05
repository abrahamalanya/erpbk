<?php

namespace App\Modules\Dashboard\Http\Requests;

use App\Modules\Dashboard\Services\DashboardService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros del resumen inicial del dashboard. Todos opcionales: sin ninguno,
 * el resumen es el del día completo dentro del alcance del actor.
 */
class DashboardInicialRequest extends FormRequest
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
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
            'asesor_id' => ['nullable', 'integer', 'exists:users,id'],
            'tipo_credito' => ['nullable', 'string', Rule::in(DashboardService::TIPOS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'agencia_id.exists' => 'La agencia indicada no existe',
            'asesor_id.exists' => 'El asesor indicado no existe',
            'tipo_credito.in' => 'El tipo de crédito no es válido',
        ];
    }

    public function agenciaId(): ?int
    {
        return $this->filled('agencia_id') ? $this->integer('agencia_id') : null;
    }

    /**
     * Asesor por el que se filtró, resuelto a un id real: el frontend manda el
     * id y acá se normaliza a null cuando no viene.
     */
    public function asesorId(): ?int
    {
        return $this->filled('asesor_id') ? $this->integer('asesor_id') : null;
    }

    public function tipoCredito(): ?string
    {
        return $this->filled('tipo_credito') ? (string) $this->string('tipo_credito') : null;
    }
}
