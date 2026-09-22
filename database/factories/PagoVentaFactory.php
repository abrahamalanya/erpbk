<?php

namespace Database\Factories;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\PagoVenta;
use App\Modules\Venta\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PagoVenta>
 */
class PagoVentaFactory extends Factory
{
    protected $model = PagoVenta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venta_id' => Venta::factory(),
            'cuota_venta_id' => null,
            'empresa_id' => Empresa::factory(),
            'caja_ciclo_id' => null,
            'caja_movimiento_id' => null,
            'registrado_por' => User::factory(),
            'tipo' => 'contado',
            'monto' => fake()->randomFloat(2, 50, 1000),
            'medio' => fake()->randomElement(['efectivo', 'yape', 'plin', 'transferencia']),
            'anulado_por' => null,
            'anulado_at' => null,
            'motivo_anulacion' => null,
        ];
    }
}
