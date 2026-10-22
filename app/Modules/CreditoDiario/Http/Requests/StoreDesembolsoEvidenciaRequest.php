<?php

namespace App\Modules\CreditoDiario\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDesembolsoEvidenciaRequest extends FormRequest
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
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm', 'max:51200'],
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
            'precision_metros' => ['nullable', 'numeric', 'min:0'],
            'capturado_en' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'video.required' => 'El video de evidencia es requerido',
            'video.mimetypes' => 'El video debe ser un archivo de video válido (mp4, mov, avi o webm)',
            'video.max' => 'El video no puede superar los 50MB',
            'latitud.required' => 'La ubicación es requerida',
            'longitud.required' => 'La ubicación es requerida',
        ];
    }
}
