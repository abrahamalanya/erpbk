<?php

namespace App\Modules\Ruta\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MostrarRutaCobranzaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `tipo_credito` y `tipo_cuota` (frecuencia de pago) son filtros
     * independientes: el primero decide CUÁL ruta (cada tipo tiene su propio
     * orden persistido en clientes_ruta_orden); el segundo solo recorta, sin
     * crear una ruta ni un orden propios — ver RutaCobranzaService. Ninguno
     * de los dos valida acá si el actor tiene ese tipo habilitado: eso lo
     * hace el servicio (mismo criterio que el resto del dashboard — un
     * filtro nunca amplía lo que el actor puede ver, así que pedir un tipo no
     * habilitado da una ruta vacía, no un error).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'asesor_id' => ['nullable', 'integer'],
            'tipo_credito' => ['nullable', 'string', 'in:diario,prendario,hipotecario,vehicular'],
            'tipo_cuota' => ['nullable', 'string', 'in:diario,semanal,quincenal,mensual'],
            'desde_ubicacion_actual' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_credito.in' => 'El tipo de crédito debe ser diario, prendario, hipotecario o vehicular',
            'tipo_cuota.in' => 'La frecuencia de pago debe ser diario, semanal, quincenal o mensual',
        ];
    }

    /**
     * Si el trazado debe empezar en la última ubicación GPS conocida del
     * asesor (mapa unificado, para mostrar el camino hacia la próxima
     * parada) en vez de desde la primera parada (RutaMapa/RutaMapaLive,
     * vista de planificación del día).
     */
    public function desdeUbicacionActual(): bool
    {
        return $this->boolean('desde_ubicacion_actual');
    }
}
