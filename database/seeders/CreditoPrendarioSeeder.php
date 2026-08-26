<?php

namespace Database\Seeders;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\CreditoPrendario\Services\CreditoPrendarioService;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Usuario\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class CreditoPrendarioSeeder extends Seeder
{
    /**
     * Every credit here goes through the full real lifecycle — registrar,
     * aprobar, firmar documentos, desembolsar — via CreditoPrendarioService,
     * so cuotas/documentos/movimientos are generated exactly as they would
     * be in production. What differs per bucket is WHEN it happened: each
     * one is created with Carbon's "now" frozen to a specific point in the
     * past (see crearCreditoActivo()), so fecha_desembolso/fecha_vencimiento
     * land exactly where the bucket name says, then real time is restored.
     *
     * dias_desde_desembolso = 30 (plazo_dias de un cuota mensual) +
     * días de mora deseados — o 30 - días restantes para "cerca a vencer".
     *
     * 15 vs 16 días vencido are deliberately adjacent: the seeded
     * ConfiguracionCreditoPrendario has dias_espera_mora = 15, so this pair
     * demos the exact boundary of puede_enviar_tienda (falso a los 15,
     * verdadero a los 16).
     *
     * Repeated REPETICIONES_ESCENARIO times each (below) to reach the 30
     * créditos total: 6 escenarios × 4 = 24, + 2 refrendos × 2 filas = 4,
     * + 2 liquidaciones = 2 → 30.
     *
     * @var list<array{nombre: string, dias_desde_desembolso: int, estado_final: string}>
     */
    private const ESCENARIOS = [
        ['nombre' => 'Cerca A Vencer', 'dias_desde_desembolso' => 27, 'estado_final' => 'activo'],
        ['nombre' => 'Vencido Reciente', 'dias_desde_desembolso' => 33, 'estado_final' => 'vencido'],
        ['nombre' => 'Vencido Diez Dias', 'dias_desde_desembolso' => 40, 'estado_final' => 'vencido'],
        ['nombre' => 'Vencido Quince Dias', 'dias_desde_desembolso' => 45, 'estado_final' => 'vencido'],
        ['nombre' => 'Vencido Dieciseis Dias', 'dias_desde_desembolso' => 46, 'estado_final' => 'vencido'],
        ['nombre' => 'Vencido Treinta Dias', 'dias_desde_desembolso' => 60, 'estado_final' => 'vencido'],
    ];

    private const REPETICIONES_ESCENARIO = 4;

    private const REPETICIONES_REFRENDO = 2;

    private const REPETICIONES_LIQUIDACION = 2;

    public function __construct(private readonly CreditoPrendarioService $creditoService) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Cliente::where('apellido', 'Demo')->exists()) {
            return;
        }

        $agencia = Agencia::where('nombre', 'Agencia Pucallpa')->firstOrFail();
        $asesor = User::where('email', 'asesor1.Pucallpa@laravel.com')->firstOrFail();
        $admin = User::where('email', 'ejecutivo.abrahamalanya@laravel.com')->firstOrFail();

        foreach (self::ESCENARIOS as $escenario) {
            for ($i = 1; $i <= self::REPETICIONES_ESCENARIO; $i++) {
                $credito = $this->crearCreditoActivo($agencia, $asesor, $admin, "{$escenario['nombre']} {$i}", $escenario['dias_desde_desembolso']);

                if ($escenario['estado_final'] === 'vencido') {
                    $credito->update(['estado' => 'vencido']);
                }
            }
        }

        for ($i = 1; $i <= self::REPETICIONES_REFRENDO; $i++) {
            $origenRefrendo = $this->crearCreditoActivo($agencia, $asesor, $admin, "Refrendado {$i}", 33);
            $interesRefrendo = $this->creditoService->calcularMontoRefrendo($origenRefrendo)['interes'];
            $this->creditoService->refrendar($origenRefrendo, $asesor, $interesRefrendo, 'efectivo', null);
        }

        for ($i = 1; $i <= self::REPETICIONES_LIQUIDACION; $i++) {
            $origenLiquidacion = $this->crearCreditoActivo($agencia, $asesor, $admin, "Liquidado {$i}", 10);
            $totalLiquidacion = $this->creditoService->calcularMontoLiquidacion($origenLiquidacion)['total'];
            $liquidado = $this->creditoService->liquidar($origenLiquidacion, $asesor, $totalLiquidacion, 'efectivo', null);

            // liquidar() deja el crédito en liquidado_pendiente hasta que se
            // firma el acta de devolución — mismo criterio de reutilizar una
            // muestra ya publicada que crearCreditoActivo() usa arriba.
            $devolucion = $liquidado->documentos()->where('tipo', 'devolucion')->firstOrFail();
            $devolucion->update([
                'archivo_firmado_path' => 'clientes/samples/dni1.jpeg',
                'firmado_at' => now(),
            ]);
            $this->creditoService->confirmarLiquidacionSiCorresponde($liquidado, $devolucion);
        }
    }

    private function crearCreditoActivo(Agencia $agencia, User $asesor, User $admin, string $nombreCliente, int $diasDesdeDesembolso): CreditoPrendario
    {
        $cliente = Cliente::factory()->forAgencia($agencia)->create([
            'nombre' => $nombreCliente,
            'apellido' => 'Demo',
            'asesor_id' => $asesor->id,
            'registrado_por' => $asesor->id,
        ]);

        $bien = Bien::factory()->paraCliente($cliente)->create([
            'nombre' => 'Laptop',
            'marca' => 'HP',
            'modelo' => 'ProBook',
            'valorizacion' => 800,
            'registrado_por' => $asesor->id,
            'foto_cliente_producto_path' => 'clientes/samples/cliente_producto.jpg',
        ]);
        $bien->fotos()->create(['path' => 'clientes/samples/laptop.jpg', 'orden' => 0]);

        Carbon::setTestNow(now()->subDays($diasDesdeDesembolso));

        try {
            $credito = $this->creditoService->registrar($asesor, collect([$bien]), [
                'monto_prestamo' => '500',
                'tipo_cuota' => 'mensual',
            ]);

            $credito = $this->creditoService->aprobar($credito, $admin);

            // Sube el escaneo firmado sin pasar por un upload real — reutiliza
            // una muestra ya publicada, mismo criterio que ClienteSeeder/BienSeeder.
            $credito->documentos()->update([
                'archivo_firmado_path' => 'clientes/samples/dni1.jpeg',
                'firmado_at' => now(),
            ]);

            $credito = $this->creditoService->desembolsar($credito, $asesor, null, null);
        } finally {
            Carbon::setTestNow();
        }

        return $credito;
    }
}
