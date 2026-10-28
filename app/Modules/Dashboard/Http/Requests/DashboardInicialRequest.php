<?php

namespace App\Modules\Dashboard\Http\Requests;

use App\Modules\Dashboard\Services\DashboardService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros del resumen inicial del dashboard. Todos opcionales: sin ninguno,
 * el resumen es el del día de hoy dentro del alcance del actor — el mismo
 * comportamiento que tenía el dashboard antes de que desde/hasta existieran.
 *
 * desde/hasta son el filtro global de rango de fechas del panel: lo usan los
 * endpoints cuya magnitud es un FLUJO del período (cobranza, desembolso,
 * egresos del resumen; el gráfico comparativo; el "cobrado" de la línea de
 * tiempo de atrasos). No lo usan los que son un STOCK del momento (vencidos,
 * cartera activa por tipo) ni avanceCobranzaHoy (que por definición es
 * siempre "hoy", ver DashboardService::avanceCobranzaHoy()).
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
            'agrupar_por' => ['nullable', 'string', Rule::in(DashboardService::AGRUPACIONES)],
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
            'agencia_id.exists' => 'La agencia indicada no existe',
            'asesor_id.exists' => 'El asesor indicado no existe',
            'tipo_credito.in' => 'El tipo de crédito no es válido',
            'agrupar_por.in' => 'La agrupación indicada no es válida',
            'hasta.after_or_equal' => 'La fecha "hasta" no puede ser anterior a "desde"',
        ];
    }

    public function agenciaId(): ?int
    {
        return $this->filled('agencia_id') ? $this->integer('agencia_id') : null;
    }

    /** Sin fecha explícita, el rango por defecto es hoy — mismo comportamiento que antes de este filtro. */
    public function desde(): string
    {
        return $this->filled('desde') ? (string) $this->string('desde') : now()->toDateString();
    }

    public function hasta(): string
    {
        return $this->filled('hasta') ? (string) $this->string('hasta') : now()->toDateString();
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

    /**
     * Modo de agrupación del gráfico Cobranza vs. Desembolso. `agencia` por
     * defecto: es el que ya conocían los usuarios antes de poder elegir.
     */
    public function agruparPor(): string
    {
        return $this->filled('agrupar_por') ? (string) $this->string('agrupar_por') : DashboardService::AGRUPACION_AGENCIA;
    }
}
