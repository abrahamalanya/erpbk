<?php

namespace App\Modules\Credito\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ConfirmarConformidadRequest extends FormRequest
{
    /**
     * Checked before validation so an unauthorized actor gets a 403.
     */
    public function authorize(): bool
    {
        return Gate::allows('confirmarConformidad', $this->route('credito'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:8192'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Debes adjuntar el documento de conformidad',
            'archivo.mimes' => 'La conformidad debe ser un PDF o una imagen (jpg, png)',
            'archivo.max' => 'El archivo no puede superar los 8MB',
        ];
    }
}
