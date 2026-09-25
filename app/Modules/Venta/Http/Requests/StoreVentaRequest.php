<?php

namespace App\Modules\Venta\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVentaRequest extends FormRequest
{
    /**
     * Autorización real vía Gate en el controller (necesita resolver el
     * artículo primero para poder registrar credito_origen_id, etc.).
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
            'tipo' => ['required', Rule::in(['bien', 'vehiculo', 'inmueble'])],
            'articulo_id' => ['required', 'integer'],
            'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'forma_venta' => ['required', Rule::in(['contado', 'credito', 'apartado'])],
            'medio' => ['required', Rule::in(['efectivo', 'yape', 'plin', 'transferencia'])],
            'inicial' => [Rule::requiredIf(fn (): bool => in_array($this->input('forma_venta'), ['credito', 'apartado'], true)), 'numeric', 'min:0.01'],
            'numero_cuotas' => [Rule::requiredIf(fn (): bool => $this->input('forma_venta') === 'credito'), 'integer', 'min:1'],
            'tipo_cuota' => [Rule::requiredIf(fn (): bool => $this->input('forma_venta') === 'credito'), Rule::in(['diario', 'semanal', 'quincenal', 'mensual'])],
            'interes' => ['nullable', 'numeric', 'min:0'],
            'fecha_limite' => [Rule::requiredIf(fn (): bool => $this->input('forma_venta') === 'apartado'), 'date', 'after:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'inicial.required' => 'Debes indicar el inicial para una venta a crédito o apartado.',
            'numero_cuotas.required' => 'Debes indicar el número de cuotas para una venta a crédito.',
            'tipo_cuota.required' => 'Debes indicar el tipo de cuota para una venta a crédito.',
            'tipo_cuota.in' => 'El tipo de cuota no es válido.',
            'fecha_limite.required' => 'Debes indicar la fecha de cancelación para un apartado.',
            'fecha_limite.after' => 'La fecha de cancelación debe ser posterior a hoy.',
        ];
    }
}
