<?php

namespace App\Modules\Sistemas\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConfiguracionSistemaRequest extends FormRequest
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
            'nombre_app' => ['nullable', 'string', 'max:100'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico,svg,jpg,jpeg', 'max:2048'],
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
            'nombre_app.max' => 'El nombre no puede exceder los 100 caracteres',
            'favicon.mimes' => 'El favicon debe ser png, ico, svg o jpg',
            'favicon.max' => 'El favicon no puede exceder los 2MB',
        ];
    }
}
