<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Models\FichaSocioeconomica;
use App\Modules\Empresa\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FichaSocioeconomica>
 */
class FichaSocioeconomicaFactory extends Factory
{
    protected $model = FichaSocioeconomica::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            'cliente_id' => Cliente::factory(),
            'grado_instruccion' => fake()->randomElement(['primaria', 'secundaria', 'tecnico', 'universitario']),
            'profesion' => fake()->jobTitle(),
            'laboral_institucion' => fake()->company(),
            'laboral_cargo' => fake()->jobTitle(),
            'laboral_fecha_ingreso' => fake()->dateTimeBetween('-10 years', '-1 year')->format('Y-m-d'),
            'ing_conyuge' => fake()->randomFloat(2, 0, 2000),
            'egp_alimentacion' => fake()->randomFloat(2, 0, 800),
            'egn_alquiler' => fake()->randomFloat(2, 0, 1000),
            'viv_tenencia' => 'propia',
            'viv_material' => 'noble_acabado',
            'viv_habitaciones' => 'tres',
            'viv_tipo' => 'casa_independiente',
            'viv_nro_pisos' => 2,
            'viv_piso_vive' => 1,
            'viv_redes_servicio' => ['luz_electrica', 'desague'],
            'viv_bienes_muebles' => ['refrigeradora', 'television'],
            'viv_total_activo_mueble' => fake()->randomFloat(2, 0, 5000),
            'viv_total_activo_inmueble' => fake()->randomFloat(2, 0, 100000),
            'responsable_ficha' => fake()->name(),
        ];
    }

    public function paraCliente(Cliente $cliente): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $cliente->empresa_id,
            'cliente_id' => $cliente->id,
        ]);
    }
}
