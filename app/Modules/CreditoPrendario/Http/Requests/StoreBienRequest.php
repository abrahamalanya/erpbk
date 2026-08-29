<?php

namespace App\Modules\CreditoPrendario\Http\Requests;

use App\Modules\Cliente\Models\Cliente;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBienRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'tipo' => ['required', Rule::in(['electro', 'varios'])],
            'nombre' => ['required', 'string', 'max:255'],
            'marca' => ['required', 'string', 'max:255'],
            'modelo' => ['required', 'string', 'max:255'],
            'serie' => ['nullable', 'string', 'max:255'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'valorizacion' => ['required', 'numeric', 'min:0'],
            'puntaje' => ['required', 'integer', 'min:1', 'max:10'],
            'foto_cliente_producto' => ['nullable', 'image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'fotos' => ['nullable', 'array', 'max:10'],
            'fotos.*' => ['image', 'max:8192', 'mimes:jpg,jpeg,png'],
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/webm', 'max:51200'],
        ];
    }

    /**
     * Confirms the cliente is within the actor's own tenant scope — mirrors
     * the same cross-entity ownership check StoreClienteRequest does for
     * agencia_id/empresa_id.
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
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cliente_id.required' => 'El cliente es requerido',
            'cliente_id.exists' => 'El cliente indicado no existe',
            'tipo.required' => 'El tipo de bien es requerido',
            'tipo.in' => 'El tipo de bien no es válido',
            'nombre.required' => 'El nombre del bien es requerido',
            'marca.required' => 'La marca es requerida',
            'modelo.required' => 'El modelo es requerido',
            'valorizacion.required' => 'La valorización es requerida',
            'valorizacion.numeric' => 'La valorización debe ser un número',
            'puntaje.required' => 'El puntaje es requerido',
            'puntaje.min' => 'El puntaje debe estar entre 1 y 10',
            'puntaje.max' => 'El puntaje debe estar entre 1 y 10',
            'video.mimetypes' => 'El video debe ser un archivo de video válido (mp4, mov, avi o webm)',
            'video.max' => 'El video no puede superar los 50MB',
            'fotos.max' => 'No puedes subir más de 10 fotos',
        ];
    }
}
