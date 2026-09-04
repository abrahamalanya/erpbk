<?php

namespace App\Modules\CreditoVehicular\Http\Requests;

use App\Modules\Cliente\Models\Cliente;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreVehiculoRequest extends FormRequest
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
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
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
     * Confirms the cliente is within the actor's own tenant scope — mirrors
     * StoreBienRequest.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $cliente = Cliente::find($this->input('cliente_id'));

            if (! $cliente) {
                return;
            }

            $actor = $this->user();

            if ($actor->hasRole('sistemas')) {
                return;
            }

            if ($actor->empresa_id !== $cliente->empresa_id) {
                $validator->errors()->add('cliente_id', 'El cliente no pertenece a tu empresa.');

                return;
            }

            if ($actor->agencia_id !== null && $actor->agencia_id !== $cliente->agencia_id) {
                $validator->errors()->add('cliente_id', 'El cliente no pertenece a tu agencia.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cliente_id.required' => 'El cliente es requerido',
            'cliente_id.exists' => 'El cliente indicado no existe',
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
