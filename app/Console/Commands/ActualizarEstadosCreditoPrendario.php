<?php

namespace App\Console\Commands;

use App\Modules\Credito\Services\CreditoService;
use Illuminate\Console\Command;

class ActualizarEstadosCreditoPrendario extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'creditos-prendarios:actualizar-estados';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Transiciona créditos prendarios vencidos a "vencido" y, tras el periodo de gracia configurado, a "en_venta"';

    /**
     * Execute the console command.
     */
    public function handle(CreditoService $creditoService): int
    {
        $creditoService->actualizarEstadosVencidos();

        $this->info('Estados de créditos prendarios actualizados.');

        return self::SUCCESS;
    }
}
