<?php

namespace App\Modules\Credito\Http\Requests;

use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Tipos\CreditoTipoManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class EnviarATiendaRequest extends FormRequest
{
    /**
     * Checked before validation, so an unauthorized actor gets a 403 rather
     * than leaking the precios rules.
     */
    public function authorize(): bool
    {
        return Gate::allows('enviarATienda', $this->route('credito'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `precios` is a { bien_id: precio_venta } map — the sale price the
     * storefront will show for each bien of the crédito.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'precios' => ['required', 'array'],
            'precios.*' => ['numeric', 'min:0.01', 'max:99999999.99'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $credito = $this->route('credito');

            if (! $credito instanceof Credito) {
                return;
            }

            $precios = (array) $this->input('precios', []);

            $modelo = app(CreditoTipoManager::class)->paraCredito($credito)->garantiaModelo();

            foreach ($credito->garantiasComo($modelo)->get() as $garantia) {
                if (! isset($precios[$garantia->id]) && ! isset($precios[(string) $garantia->id])) {
                    $validator->errors()->add('precios', "Falta el precio de venta de \"{$garantia->nombre}\".");
                }
            }
        });
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'precios.required' => 'Debes indicar el precio de venta de cada bien',
            'precios.*.numeric' => 'El precio de venta debe ser un número',
            'precios.*.min' => 'El precio de venta debe ser mayor a cero',
        ];
    }
}
