<?php

namespace Database\Factories;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Venta>
 */
class VentaFactory extends Factory
{
    protected $model = Venta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $precio = fake()->randomFloat(2, 200, 3000);

        return [
            'empresa_id' => Empresa::factory(),
            'agencia_id' => Agencia::factory(),
            'articulo_type' => 'bien',
            'articulo_id' => Bien::factory(),
            'credito_origen_id' => null,
            'cliente_id' => Cliente::factory(),
            'vendido_por' => User::factory(),
            'forma_venta' => 'contado',
            'estado' => 'pagada',
            'precio_venta' => $precio,
            'inicial' => $precio,
            'interes' => null,
            'numero_cuotas' => null,
            'fecha_limite' => null,
            'saldo_pendiente' => 0,
            'pagada_at' => now(),
            'cancelada_at' => null,
        ];
    }

    /**
     * Attach to a specific artículo (bien/vehículo/inmueble), keeping
     * empresa/agencia consistent with it.
     */
    public function paraArticulo(Model $articulo): static
    {
        return $this->state(fn (): array => [
            'empresa_id' => $articulo->empresa_id,
            'agencia_id' => $articulo->agencia_id,
            'articulo_type' => $articulo->getMorphClass(),
            'articulo_id' => $articulo->id,
        ]);
    }
}
