<?php

namespace Database\Seeders;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Venta\Models\ConfiguracionVenta;
use Illuminate\Database\Seeder;

class ConfiguracionVentaSeeder extends Seeder
{
    private const INTERES_MENSUAL_DEFAULT = 5;

    /**
     * Configuración base para las ventas a crédito. Se crea una fila
     * default por empresa; una agencia puede tener un override posterior.
     *
     * La tasa inicial es un valor de trabajo para la instalación nueva; debe
     * ajustarse desde la configuración de ventas según la política comercial
     * de cada empresa.
     */
    public function run(): void
    {
        Empresa::query()
            ->where('estado', 'activo')
            ->each(function (Empresa $empresa): void {
                ConfiguracionVenta::query()->firstOrCreate(
                    [
                        'empresa_id' => $empresa->id,
                        'agencia_id' => null,
                    ],
                    [
                        'interes_mensual_default' => self::INTERES_MENSUAL_DEFAULT,
                    ],
                );
            });
    }
}
