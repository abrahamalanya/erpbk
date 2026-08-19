<?php

namespace App\Modules\CreditoPrendario\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBienRequest extends FormRequest
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
            'tipo' => ['required', Rule::in(['electro', 'varios'])],
            'nombre' => ['required', 'string', 'max:255'],
            'marca' => [Rule::requiredIf(fn (): bool => $this->input('tipo') === 'electro'), 'nullable', 'string', 'max:255'],
            'modelo' => [Rule::requiredIf(fn (): bool => $this->input('tipo') === 'electro'), 'nullable', 'string', 'max:255'],
            'serie' => ['nullable', 'string', 'max:255'],
            'observacion' => ['nullable', 'string', 'max:2000'],
            'valorizacion' => ['required', 'numeric', 'min:0'],
            'cantidad' => ['nullable', 'integer', 'min:1'],
            'foto_cliente_producto' => ['nullable', 'image', 'max:4096', 'mimes:jpg,jpeg,png'],
            'fotos' => ['nullable', 'array'],
            'fotos.*' => ['image', 'max:4096', 'mimes:jpg,jpeg,png'],
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'El tipo de bien es requerido',
            'tipo.in' => 'El tipo de bien no es válido',
            'nombre.required' => 'El nombre del bien es requerido',
            'marca.required' => 'La marca es requerida para bienes tipo Electro',
            'modelo.required' => 'El modelo es requerido para bienes tipo Electro',
            'valorizacion.required' => 'La valorización es requerida',
            'valorizacion.numeric' => 'La valorización debe ser un número',
        ];
    }
}
