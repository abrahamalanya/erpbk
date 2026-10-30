<?php

namespace App\Modules\Ubicacion\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreUbicacionAsesorRequest extends FormRequest
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
            'dispositivo_id' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
            'precision_metros' => ['nullable', 'numeric', 'min:0'],
            'velocidad_ms' => ['nullable', 'numeric', 'min:0'],
            'capturado_en' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
