<?php

namespace Database\Factories;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Venta\Models\CuotaVenta;
use App\Modules\Venta\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuotaVenta>
 */
class CuotaVentaFactory extends Factory
{
    protected $model = CuotaVenta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venta_id' => Venta::factory(),
            'empresa_id' => Empresa::factory(),
            'numero_cuota' => 1,
            'fecha_vencimiento' => now()->addMonth()->toDateString(),
            'monto_capital' => fake()->randomFloat(2, 100, 500),
            'monto_interes' => fake()->randomFloat(2, 0, 50),
            'monto_total' => fake()->randomFloat(2, 100, 550),
            'monto_abonado' => 0,
            'estado' => 'pendiente',
        ];
    }
}
