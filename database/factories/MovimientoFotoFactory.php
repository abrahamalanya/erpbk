<?php

namespace Database\Factories;

use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Caja\Models\MovimientoFoto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<MovimientoFoto>
 */
class MovimientoFotoFactory extends Factory
{
    protected $model = MovimientoFoto::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fotografiable_type' => CajaMovimiento::class,
            'fotografiable_id' => CajaMovimiento::factory(),
            'tipo' => 'adicional',
            'path' => 'caja-movimientos/'.fake()->uuid().'.jpg',
            'orden' => 0,
        ];
    }

    /**
     * Attach this foto to the given movimiento (any fotografiable model).
     */
    public function para(Model $fotografiable): static
    {
        return $this->state(fn (): array => [
            'fotografiable_type' => $fotografiable::class,
            'fotografiable_id' => $fotografiable->id,
        ]);
    }
}
