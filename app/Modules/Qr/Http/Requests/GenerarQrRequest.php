<?php

namespace App\Modules\Qr\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GenerarQrRequest extends FormRequest
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
            'url' => ['bail', 'required', 'string', 'url:http,https', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen($value) > 2048) {
                    $fail('La URL no debe superar 2048 bytes para generar el QR.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'Ingresa una URL',
            'url.url' => 'La URL no es válida',
            'url.string' => 'La URL debe ser un texto',
            'url.max' => 'La URL no debe superar 2048 caracteres',
        ];
    }

    public function url(): string
    {
        return (string) $this->string('url');
    }
}
