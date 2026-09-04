<?php

namespace App\Modules\CreditoVehicular\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVehiculoRequest extends FormRequest
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
            'placa' => ['required', 'string', 'max:20'],
            'motor' => ['required', 'string', 'max:60'],
            'serie' => ['required', 'string', 'max:60'],
            'color' => ['required', 'string', 'max:60'],
            'marca' => ['required', 'string', 'max:120'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'anio' => ['nullable', 'integer', 'min:1950', 'max:'.(date('Y') + 1)],
            'clase' => ['nullable', 'string', 'max:60'],
            'propietario' => ['required', 'string', 'max:255'],
            'tiene_soat' => ['required', 'boolean'],
            'dejo_llave' => ['required', 'boolean'],
            'dejo_tarjeta_propiedad' => ['required', 'boolean'],
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
            'placa.required' => 'La placa es requerida',
            'motor.required' => 'El número de motor es requerido',
            'serie.required' => 'El número de serie es requerido',
            'color.required' => 'El color es requerido',
            'marca.required' => 'La marca es requerida',
            'propietario.required' => 'El propietario (según tarjeta de propiedad) es requerido',
            'tiene_soat.required' => 'Indica si el vehículo cuenta con SOAT',
            'dejo_llave.required' => 'Indica si el cliente dejó la llave',
            'dejo_tarjeta_propiedad.required' => 'Indica si el cliente dejó la tarjeta de propiedad',
            'valorizacion.required' => 'La valorización es requerida',
            'video.mimetypes' => 'El video debe ser un archivo de video válido (mp4, mov, avi o webm)',
            'video.max' => 'El video no puede superar los 50MB',
            'fotos.max' => 'No puedes subir más de 10 fotos',
        ];
    }
}
