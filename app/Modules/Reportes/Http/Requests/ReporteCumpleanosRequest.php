<?php

namespace App\Modules\Reportes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros del reporte de cumpleaños. desde/hasta son obligatorios y van juntos:
 * el reporte compara el día/mes del cumpleaños contra ese rango, así que con
 * solo uno de los dos no hay forma de resolver la ventana.
 */
class ReporteCumpleanosRequest extends FormRequest
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
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'agencia_id' => ['nullable', 'integer', 'exists:agencias,id'],
            'usuario_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'desde.required' => 'La fecha de inicio es requerida',
            'desde.date' => 'La fecha de inicio no es válida',
            'hasta.required' => 'La fecha de fin es requerida',
            'hasta.date' => 'La fecha de fin no es válida',
            'hasta.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio',
            'agencia_id.exists' => 'La agencia indicada no existe',
            'usuario_id.exists' => 'El usuario indicado no existe',
        ];
    }

    public function desde(): string
    {
        return (string) $this->string('desde');
    }

    public function hasta(): string
    {
        return (string) $this->string('hasta');
    }

    public function agenciaId(): ?int
    {
        return $this->filled('agencia_id') ? $this->integer('agencia_id') : null;
    }

    public function usuarioId(): ?int
    {
        return $this->filled('usuario_id') ? $this->integer('usuario_id') : null;
    }
}
