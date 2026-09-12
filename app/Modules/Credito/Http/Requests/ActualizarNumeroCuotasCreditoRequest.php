<?php

namespace App\Modules\Credito\Http\Requests;

use App\Modules\Credito\Http\Requests\Concerns\ValidaNumeroCuotas;
use App\Modules\Credito\Models\Credito;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ActualizarNumeroCuotasCreditoRequest extends FormRequest
{
    use ValidaNumeroCuotas;

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
        /** @var Credito $credito */
        $credito = $this->route('credito');

        return [
            'numero_cuotas' => $this->reglasNumeroCuotasRequerido($credito->tipo_credito),
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
            'numero_cuotas.required' => 'El número de cuotas es requerido',
            'numero_cuotas.integer' => 'El número de cuotas no es válido',
            'numero_cuotas.min' => 'El número de cuotas debe ser al menos 1',
        ];
    }
}
