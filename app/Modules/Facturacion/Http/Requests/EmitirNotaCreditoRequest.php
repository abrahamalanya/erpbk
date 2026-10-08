<?php

namespace App\Modules\Facturacion\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request para emitir una nota de crédito.
 *
 * Las líneas deben incluir: descripcion, cantidad, valor_unitario, igv, total,
 * unidad_medida (opcional, default ZZ), categoria (opcional, default S).
 */
class EmitirNotaCreditoRequest extends FormRequest
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
            'motivo' => ['required', 'string', 'max:500'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.descripcion' => ['required', 'string', 'max:500'],
            'lineas.*.cantidad' => ['required', 'numeric', 'min:0.01'],
            'lineas.*.valor_unitario' => ['required', 'numeric', 'min:0'],
            'lineas.*.igv' => ['required', 'numeric', 'min:0'],
            'lineas.*.total' => ['required', 'numeric', 'min:0'],
            'lineas.*.unidad_medida' => ['sometimes', 'string', 'max:10', Rule::in(['ZZ', 'NIU', 'KG', 'MTR', 'LTR'])],
            'lineas.*.categoria' => ['sometimes', 'string', 'max:1', Rule::in(['S', 'G', 'E', 'B'])],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'El motivo de la nota de crédito es requerido',
            'lineas.required' => 'Debe incluir al menos una línea en la nota de crédito',
            'lineas.min' => 'Debe incluir al menos una línea en la nota de crédito',
        ];
    }

    /**
     * Prepara los datos: calcular valor_venta si no viene.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('lineas')) {
            $lineas = $this->input('lineas');
            foreach ($lineas as &$linea) {
                if (! isset($linea['valor_venta']) && isset($linea['total']) && isset($linea['igv'])) {
                    $linea['valor_venta'] = bcsub((string) $linea['total'], (string) $linea['igv'], 2);
                }
                if (! isset($linea['unidad_medida'])) {
                    $linea['unidad_medida'] = 'ZZ';
                }
                if (! isset($linea['categoria'])) {
                    $linea['categoria'] = 'S';
                }
            }
            $this->merge(['lineas' => $lineas]);
        }
    }
}