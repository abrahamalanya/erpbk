<?php

namespace App\Modules\Tienda\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreInteresRequest extends FormRequest
{
    /**
     * Public endpoint — anyone browsing the tienda can submit interest.
     */
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
            'nombre' => ['required', 'string', 'max:255'],
            'telefono' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'mensaje' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre es requerido',
            'telefono.required' => 'El teléfono es requerido',
            'email.email' => 'El email no es válido',
        ];
    }
}
