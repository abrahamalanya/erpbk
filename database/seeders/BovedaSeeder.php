<?php

namespace Database\Seeders;

use App\Modules\Caja\Models\Billetaje;
use App\Modules\Caja\Services\BilletajeService;
use App\Modules\Caja\Services\BovedaService;
use App\Modules\Caja\Services\CajaService;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Seeder;

class BovedaSeeder extends Seeder
{
    public function __construct(
        private readonly BovedaService $bovedaService,
        private readonly CajaService $cajaService,
        private readonly BilletajeService $billetajeService,
    ) {}

    /**
     * Seeds the initial cash flow, entirely in efectivo, from the top down:
     * bóveda principal (100 000) -> traspaso a la bóveda de Agencia Pucallpa
     * (50 000) -> billetaje solicitado y aprobado para un asesor (30 000).
     * Leaves: principal 50 000, agencia Pucallpa 20 000, caja del asesor
     * 30 000.
     */
    public function run(): void
    {
        $credimas = Empresa::where('nombre', 'CREDIMAS')->firstOrFail();
        $pucallpa = Agencia::where('nombre', 'Agencia Pucallpa')->firstOrFail();

        $adminGeneral = User::where('email', 'admin.abrahamalanya@laravel.com')->firstOrFail();
        $adminAgencia = User::where('email', 'ejecutivo.abrahamalanya@laravel.com')->firstOrFail();
        $asesor = User::where('email', 'asesor1.Pucallpa@laravel.com')->firstOrFail();

        $bovedaPrincipal = $this->bovedaService->principalDe($credimas->id);

        if ($bovedaPrincipal->ciclos()->doesntExist()) {
            $this->bovedaService->aperturar($bovedaPrincipal, $adminGeneral, '100000');
        }

        $bovedaAgencia = $this->bovedaService->deAgencia($pucallpa->id);

        if ($bovedaAgencia->ciclos()->doesntExist()) {
            $this->bovedaService->inyectar($bovedaAgencia, $adminGeneral, '50000', 'Traspaso inicial a Agencia Pucallpa', 'efectivo');
        }

        if ($this->cajaService->cajaDe($asesor)->cicloAbierto()->doesntExist()) {
            $this->cajaService->aperturar($asesor);
        }

        if (Billetaje::where('solicitado_por', $asesor->id)->doesntExist()) {
            $billetaje = $this->billetajeService->solicitar($asesor, '30000', 'Fondo inicial para operar', 'efectivo', null);
            $this->billetajeService->aprobar($billetaje, $adminAgencia, 'efectivo');
        }
    }
}
