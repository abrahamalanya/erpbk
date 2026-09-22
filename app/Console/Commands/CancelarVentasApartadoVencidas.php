<?php

namespace App\Console\Commands;

use App\Modules\Venta\Services\VentaService;
use Illuminate\Console\Command;

class CancelarVentasApartadoVencidas extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ventas:cancelar-apartados-vencidos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cancela las ventas apartado cuya fecha de cancelación venció sin cubrir el saldo; el artículo vuelve a la tienda';

    /**
     * Execute the console command.
     */
    public function handle(VentaService $ventaService): int
    {
        $total = $ventaService->cancelarVencidas();

        $this->info("Apartados vencidos cancelados: {$total}.");

        return self::SUCCESS;
    }
}
