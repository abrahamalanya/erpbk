<?php

namespace App\Modules\Ubicacion\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HistorialUbicacionAsesorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['asesor_id' => ['required', 'integer', 'exists:users,id'], 'fecha' => ['required', 'date_format:Y-m-d'], 'desde' => ['prohibited'], 'hasta' => ['prohibited']];
    }

    public function asesorId(): int
    {
        return $this->integer('asesor_id');
    }

    public function fecha(): string
    {
        return $this->string('fecha')->toString();
    }
}
