<?php

namespace App\Console\Commands;

use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Services\CajaService;
use Illuminate\Console\Command;

class CerrarCajasAutomatico extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cajas:cerrar-automatico';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cierra automáticamente todo ciclo de caja que siguió abierto pasado su propio día calendario';

    /**
     * Execute the console command.
     */
    public function handle(CajaService $cajaService): int
    {
        $ciclos = CajaCiclo::query()
            ->where('estado', 'abierta')
            ->whereDate('fecha', '<', now()->startOfDay())
            ->get();

        foreach ($ciclos as $ciclo) {
            $cajaService->cerrarAutomatico($ciclo);
        }

        $this->info("Cajas cerradas automáticamente: {$ciclos->count()}.");

        return self::SUCCESS;
    }
}
