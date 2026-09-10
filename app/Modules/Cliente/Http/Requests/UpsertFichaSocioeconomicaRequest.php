<?php

namespace App\Modules\Cliente\Http\Requests;

use App\Modules\Cliente\Models\FichaSocioeconomica;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpsertFichaSocioeconomicaRequest extends FormRequest
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
        $montos = collect([
            ...FichaSocioeconomica::CAMPOS_INGRESOS,
            ...FichaSocioeconomica::CAMPOS_EGRESOS_PERSONALES,
            ...FichaSocioeconomica::CAMPOS_EGRESOS_NEGOCIO,
            'viv_total_activo_mueble',
            'viv_total_activo_inmueble',
        ])->mapWithKeys(fn (string $col): array => [$col => ['nullable', 'numeric', 'min:0']])->all();

        return [
            ...$montos,

            'grado_instruccion' => ['nullable', 'string', 'max:255'],
            'profesion' => ['nullable', 'string', 'max:255'],

            'laboral_institucion' => ['nullable', 'string', 'max:255'],
            'laboral_cargo' => ['nullable', 'string', 'max:255'],
            'laboral_fecha_ingreso' => ['nullable', 'date'],

            'viv_tenencia' => ['nullable', 'string', 'max:50'],
            'viv_material' => ['nullable', 'string', 'max:50'],
            'viv_habitaciones' => ['nullable', 'string', 'max:50'],
            'viv_tipo' => ['nullable', 'string', 'max:50'],
            'viv_nro_pisos' => ['nullable', 'integer', 'min:0'],
            'viv_piso_vive' => ['nullable', 'integer', 'min:0'],
            'viv_agua' => ['nullable', 'string', 'max:255'],
            'viv_telefono' => ['nullable', 'string', 'max:255'],
            'viv_redes_servicio' => ['nullable', 'array'],
            'viv_redes_servicio.*' => ['string', 'max:50'],
            'viv_bienes_muebles' => ['nullable', 'array'],
            'viv_bienes_muebles.*' => ['string', 'max:50'],

            'declarante_nombres' => ['nullable', 'string', 'max:255'],
            'declarante_parentesco' => ['nullable', 'string', 'max:255'],
            'declarante_direccion' => ['nullable', 'string', 'max:255'],
            'declarante_telefono' => ['nullable', 'string', 'max:50'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'responsable_ficha' => ['nullable', 'string', 'max:255'],

            'familiares' => ['nullable', 'array'],
            'familiares.*.nombres' => ['required_with:familiares', 'string', 'max:255'],
            'familiares.*.edad' => ['nullable', 'integer', 'min:0', 'max:150'],
            'familiares.*.parentesco' => ['nullable', 'string', 'max:100'],
            'familiares.*.estado_civil' => ['nullable', 'string', 'max:100'],
            'familiares.*.ocupacion' => ['nullable', 'string', 'max:255'],
        ];
    }
}
