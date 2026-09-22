<?php

namespace Database\Factories;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\DocumentoVenta;
use App\Modules\Venta\Models\Venta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentoVenta>
 */
class DocumentoVentaFactory extends Factory
{
    protected $model = DocumentoVenta::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'venta_id' => Venta::factory(),
            'empresa_id' => Empresa::factory(),
            'tipo' => 'voucher',
            'datos' => null,
            'archivo_firmado_path' => null,
            'generado_por' => User::factory(),
            'generado_at' => now(),
            'impreso_at' => null,
            'firmado_at' => null,
        ];
    }
}
