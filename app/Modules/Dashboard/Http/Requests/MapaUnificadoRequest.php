<?php

namespace App\Modules\Dashboard\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros del mapa unificado (asesores en vivo + ruta de cobranza). Ambos
 * opcionales: sin ninguno, muestra todos los asesores/rutas que el actor
 * tenga autorización para ver.
 */
class MapaUnificadoRequest extends FormRequest
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
        ];
    }

    public function agenciaId(): ?int
    {
        return $this->filled('agencia_id') ? $this->integer('agencia_id') : null;
    }

    public function asesorId(): ?int
    {
        return $this->filled('asesor_id') ? $this->integer('asesor_id') : null;
    }
}
