<?php

namespace Database\Factories;

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Tienda\Models\InteresArticulo;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<InteresArticulo>
 */
class InteresArticuloFactory extends Factory
{
    protected $model = InteresArticulo::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bien = Bien::factory()->create();

        return [
            'articulo_type' => $bien->getMorphClass(),
            'articulo_id' => $bien->id,
            'empresa_id' => Empresa::factory(),
            'agencia_id' => Agencia::factory(),
            'nombre' => fake()->name(),
            'telefono' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'mensaje' => fake()->sentence(),
        ];
    }

    /**
     * Attach the interés to the given articulo (Bien / Vehiculo / …) and its
     * empresa/agencia.
     */
    public function paraArticulo(Model $articulo): static
    {
        return $this->state(fn (): array => [
            'articulo_type' => $articulo->getMorphClass(),
            'articulo_id' => $articulo->id,
            'empresa_id' => $articulo->empresa_id,
            'agencia_id' => $articulo->agencia_id,
        ]);
    }
}
