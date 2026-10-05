<?php

namespace App\Modules\Usuario\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El PDF se arma para los usuarios indicados explícitamente por el frontend
 * (tarjetas a imprimir). El listado de candidatos sale de GET /api/usuarios,
 * que ya devuelve la foto_url de cada uno.
 */
class FotocheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'usuario_ids' => ['required', 'array', 'min:1', 'max:60'],
            'usuario_ids.*' => ['integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'usuario_ids.required' => 'Debe seleccionar al menos un usuario',
            'usuario_ids.min' => 'Debe seleccionar al menos un usuario',
            'usuario_ids.max' => 'No se pueden imprimir más de 60 tarjetas a la vez',
            'usuario_ids.*.exists' => 'Uno de los usuarios indicados no existe',
        ];
    }

    /**
     * Ids únicos y en el orden en que se mandaron: ese orden es el del
     * imprentaje (3 arriba, 3 abajo), no el del id.
     *
     * @return list<int>
     */
    public function usuarioIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->input('usuario_ids', []))));
    }
}
