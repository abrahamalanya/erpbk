<?php

namespace App\Modules\Ubicacion\Http\Requests;

use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros del reporte de historial de recorridos: un asesor puntual y un
 * rango de fechas acotado a 31 días, para no permitir consultas sin límite
 * sobre una tabla que crece con cada ping del tracking en segundo plano.
 */
class HistorialUbicacionAsesorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'asesor_id' => ['required', 'integer', 'exists:users,id'],
            'desde' => ['required', 'date'],
            'hasta' => [
                'required',
                'date',
                'after_or_equal:desde',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->filled('desde') && Carbon::parse($value)->diffInDays(Carbon::parse($this->string('desde'))) > 31) {
                        $fail('El rango de fechas no puede superar los 31 días.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'asesor_id.required' => 'El asesor es requerido',
            'asesor_id.exists' => 'El asesor indicado no existe',
            'desde.required' => 'La fecha de inicio es requerida',
            'hasta.required' => 'La fecha de fin es requerida',
            'hasta.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio',
        ];
    }

    public function asesorId(): int
    {
        return $this->integer('asesor_id');
    }

    public function desde(): string
    {
        return (string) $this->string('desde');
    }

    public function hasta(): string
    {
        return (string) $this->string('hasta');
    }
}
