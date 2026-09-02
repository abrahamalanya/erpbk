<?php

namespace App\Modules\CreditoHipotecario\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInmuebleRequest extends FormRequest
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
            'partida_registral' => ['required', 'string', 'max:60'],
            'oficina_registral' => ['nullable', 'string', 'max:120'],
            'tipo_inmueble' => ['nullable', 'string', 'max:60'],
            'direccion' => ['required', 'string', 'max:255'],
            'distrito' => ['nullable', 'string', 'max:120'],
            'provincia' => ['nullable', 'string', 'max:120'],
            'departamento' => ['nullable', 'string', 'max:120'],
            'area_terreno' => ['nullable', 'numeric', 'min:0'],
            'area_construida' => ['nullable', 'numeric', 'min:0'],
            'propietario' => ['required', 'string', 'max:255'],
            'con_gravamen' => ['required', 'boolean'],
            'linderos' => ['nullable', 'string', 'max:2000'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'valorizacion' => ['required', 'numeric', 'min:0'],
            'puntaje' => ['nullable', 'integer', 'min:1', 'max:10'],
            'foto_cliente_producto' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'fotos' => ['nullable', 'array', 'max:10'],
            'fotos.*' => ['image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm', 'max:51200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'partida_registral.required' => 'La partida registral es requerida',
            'direccion.required' => 'La dirección del inmueble es requerida',
            'propietario.required' => 'El propietario (según partida registral) es requerido',
            'con_gravamen.required' => 'Indica si el inmueble tiene algún gravamen',
            'valorizacion.required' => 'La valorización es requerida',
            'video.mimetypes' => 'El video debe ser un archivo de video válido (mp4, mov, avi o webm)',
            'video.max' => 'El video no puede superar los 50MB',
            'fotos.max' => 'No puedes subir más de 10 fotos',
        ];
    }
}
