<?php

namespace App\Modules\Dashboard\Http\Requests;

use App\Modules\Dashboard\Services\DashboardService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros de "Créditos generados por asesor" (producción). A diferencia de
 * DashboardInicialRequest, lleva un período (desde/hasta) — el resto de
 * Inicio es siempre "hoy", pero la producción se analiza por rango, igual
 * que el reporte de cobranza mensual.
 */
class DashboardProduccionRequest extends FormRequest
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
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
            'asesor_id' => ['nullable', 'integer', 'exists:users,id'],
            'tipo_credito' => ['nullable', 'string', Rule::in(DashboardService::TIPOS_CREDITO)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a "desde"',
            'agencia_id.exists' => 'La agencia indicada no existe',
            'asesor_id.exists' => 'El asesor indicado no existe',
            'tipo_credito.in' => 'El tipo de crédito no es válido',
        ];
    }

    /** Sin fecha explícita, el período por defecto es el mes en curso hasta hoy. */
    public function desde(): string
    {
        return $this->filled('desde') ? (string) $this->string('desde') : now()->startOfMonth()->toDateString();
    }

    public function hasta(): string
    {
        return $this->filled('hasta') ? (string) $this->string('hasta') : now()->toDateString();
    }

    public function agenciaId(): ?int
    {
        return $this->filled('agencia_id') ? $this->integer('agencia_id') : null;
    }

    public function asesorId(): ?int
    {
        return $this->filled('asesor_id') ? $this->integer('asesor_id') : null;
    }

    public function tipoCredito(): ?string
    {
        return $this->filled('tipo_credito') ? (string) $this->string('tipo_credito') : null;
    }
}
