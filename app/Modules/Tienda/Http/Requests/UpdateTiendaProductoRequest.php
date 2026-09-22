<?php

namespace App\Modules\Tienda\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTiendaProductoRequest extends FormRequest
{
    /**
     * Autorización real vía Gate en el controller (reusa la Policy propia
     * de cada tipo de garantía — bienes.editar / vehiculos.editar / inmuebles.editar).
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
            'precio_venta' => ['sometimes', 'numeric', 'min:0.01', 'max:99999999.99'],
            'precio_oferta' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'estado' => ['sometimes', Rule::in(['disponible_venta', 'retirado_venta'])],
        ];
    }
}
