<?php

namespace App\Modules\CreditoPrendario\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DesembolsarCreditoRequest extends FormRequest
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
            'numero_cuotas' => ['nullable', 'integer', 'min:1'],
            'interes' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
