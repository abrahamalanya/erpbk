<?php

namespace App\Modules\Caja\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreCajaMovimientoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza la boleta antes de validar: recorta espacios y sube la serie a
     * mayúsculas, para que " b001 " y "B001" no cuenten como boletas distintas
     * en la regla de unicidad. Los strings vacíos se convierten en null para
     * no guardarlos como "" (que rompería el índice único de la BD).
     */
    protected function prepareForValidation(): void
    {
        $boleta = [];

        foreach (['serie', 'numero'] as $campo) {
            $valor = $this->input($campo);

            if (! is_string($valor)) {
                continue;
            }

            $valor = trim($valor);
            $boleta[$campo] = $campo === 'serie' ? mb_strtoupper($valor) : $valor;
        }

        $this->merge(array_map(fn (string $valor): ?string => $valor === '' ? null : $valor, $boleta));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Serie y número identifican el comprobante (boleta/factura) del
     * movimiento. Son opcionales porque el billetaje, las ventas de tienda y
     * los desembolsos de crédito no emiten boleta, pero viajan juntos:
     * `required_with` obliga a mandar los dos o ninguno. La unicidad es por
     * empresa, igual que los documentos de cliente.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', 'in:ingreso,egreso'],
            'concepto_id' => ['required', 'integer', 'exists:conceptos,id'],
            'monto' => ['required', 'numeric', 'min:0.01'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'serie' => ['nullable', 'string', 'max:10', 'required_with:numero', $this->boletaUnica('serie', 'numero')],
            'numero' => ['nullable', 'string', 'max:20', 'required_with:serie', $this->boletaUnica('numero', 'serie')],
            'comprobante' => ['required_if:tipo,egreso', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'fotos_adicionales' => ['sometimes', 'array', 'max:10'],
            'fotos_adicionales.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
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
            'tipo.required' => 'El tipo es requerido',
            'tipo.in' => 'El tipo debe ser ingreso o egreso',
            'concepto_id.required' => 'El concepto es requerido',
            'concepto_id.exists' => 'El concepto seleccionado no existe',
            'monto.required' => 'El monto es requerido',
            'monto.min' => 'El monto debe ser mayor a cero',
            'serie.required_with' => 'Ingresa también la serie de la boleta',
            'serie.unique' => 'Esta boleta ya fue registrada en tu empresa',
            'numero.required_with' => 'Ingresa también el número de la boleta',
            'numero.unique' => 'Esta boleta ya fue registrada en tu empresa',
            'comprobante.required_if' => 'El comprobante de pago es requerido para registrar un gasto',
            'fotos_adicionales.max' => 'No puedes subir más de 10 fotos adicionales',
        ];
    }

    /**
     * La serie y el número identifican la boleta juntos, así que la unicidad se
     * evalúa sobre la pareja completa y no sobre el número solo: B001-0001 y
     * B002-0001 son boletas distintas. Cada campo valida su propia columna
     * contra el valor de la otra, que es lo que espera el presence verifier
     * (compara $columna = $valor del atributo bajo validación más las
     * condiciones extra). Se acota a la empresa del actor —misma regla que los
     * documentos de cliente—, de modo que la comparación use exactamente el
     * par normalizado en prepareForValidation(), que es a su vez el que
     * sostiene el índice único `caja_movimientos_boleta_unica` de la tabla.
     */
    private function boletaUnica(string $columna, string $pareja): Unique
    {
        return Rule::unique('caja_movimientos', $columna)->where(
            fn ($query) => $query
                ->where('empresa_id', $this->user()?->empresa_id)
                ->where($pareja, $this->input($pareja))
        );
    }
}
