<?php

namespace Database\Seeders;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\CuentaBancaria;
use App\Modules\Caja\Services\BovedaService;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Models\Banco;
use Illuminate\Database\Seeder;

class CuentaBancariaSeeder extends Seeder
{
    public function __construct(private readonly BovedaService $bovedaService) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $credimas = Empresa::where('nombre', 'CREDIMAS')->firstOrFail();
        $pucallpa = Agencia::where('nombre', 'Agencia Pucallpa')->firstOrFail();

        $bovedaPrincipal = $this->bovedaService->principalDe($credimas->id);
        $bovedaPucallpa = $this->bovedaService->deAgencia($pucallpa->id);

        $this->crearCuentas($bovedaPrincipal, $credimas->nombre, [
            ['banco' => 'BCP', 'tipo_cuenta' => 'corriente'],
            ['banco' => 'Interbank', 'tipo_cuenta' => 'ahorro', 'acepta_yape' => true, 'numero_yape' => '987654321'],
            ['banco' => 'BBVA', 'tipo_cuenta' => 'corriente', 'acepta_plin' => true, 'numero_plin' => '912345678'],
            ['banco' => 'Scotiabank', 'tipo_cuenta' => 'ahorro'],
        ]);

        $this->crearCuentas($bovedaPucallpa, $credimas->nombre, [
            ['banco' => 'Banco de la Nación', 'tipo_cuenta' => 'corriente', 'acepta_yape' => true, 'numero_yape' => '923456789'],
            ['banco' => 'Banco Pichincha', 'tipo_cuenta' => 'ahorro'],
            ['banco' => 'Banco Falabella', 'tipo_cuenta' => 'corriente'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $cuentas
     */
    private function crearCuentas(Boveda $boveda, string $titular, array $cuentas): void
    {
        foreach ($cuentas as $datos) {
            $banco = Banco::where('nombre', $datos['banco'])->firstOrFail();

            CuentaBancaria::create([
                'boveda_id' => $boveda->id,
                'empresa_id' => $boveda->empresa_id,
                'banco_id' => $banco->id,
                'numero_cuenta' => fake()->numerify('###-#########-#-##'),
                'titular' => $titular,
                'tipo_cuenta' => $datos['tipo_cuenta'],
                'moneda' => 'PEN',
                'activa' => true,
                'acepta_yape' => $datos['acepta_yape'] ?? false,
                'numero_yape' => $datos['numero_yape'] ?? null,
                'acepta_plin' => $datos['acepta_plin'] ?? false,
                'numero_plin' => $datos['numero_plin'] ?? null,
                'saldo_inicial' => 0,
            ]);
        }
    }
}
