<?php

namespace App\Modules\Dashboard\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtro del "ver detalle" de avanceClientesHoy: a diferencia del resto del
 * dashboard, acá `asesor_id` es obligatorio — es el detalle de UN asesor
 * puntual (el que se tocó en el widget), no un desglose de varios. El
 * controller valida por separado que ese asesor esté dentro del alcance del
 * actor (DashboardService::asesorVisibleEnDashboard()).
 */
class DashboardClientesCobradosHoyRequest extends FormRequest
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
            'asesor_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    public function asesorId(): int
    {
        return $this->integer('asesor_id');
    }
}
