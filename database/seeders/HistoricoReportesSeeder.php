<?php

namespace Database\Seeders;

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Cobranza\Models\CobroCuotaAbono;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Histórico local para los gráficos de cobranza, desembolsos y flujo de caja.
 *
 * Los servicios de dominio registran los movimientos en el ciclo de caja
 * abierto del actor. Para este dataset se crean snapshots históricos de
 * créditos y movimientos directamente, de modo que las fechas de caja y de
 * cobro caigan realmente en cada mes del año. Los ciclos de caja son
 * snapshots con saldo de apertura sintético: no generan movimientos de
 * bóveda ni intendemos representar un ciclo real de la agencia. No debe
 * ejecutarse en producción: es únicamente una fuente de datos demo.
 */
class HistoricoReportesSeeder extends Seeder
{
    /** @var list<int> */
    private const DIAS_DESEMBOLSO = [5, 12];

    /** @var list<int> */
    private const DIAS_COBRO = [18, 23];

    /**
     * El mes en curso se ancla siempre al día 1 para que una segunda
     * ejecución, aunque sea otro día del mismo mes, no duplique movimientos.
     */
    private const DIA_MES_EN_CURSO = 1;

    /** Año desde el que se construye el histórico demo. */
    private const ANIO_INICIO = 2026;

    private const SALDO_APERTURA_HISTORICO = 10000;

    private const MEDIOS_COBRO = ['efectivo', 'efectivo', 'yape', 'transferencia'];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('HistoricoReportesSeeder sólo puede ejecutarse en el entorno local.');
        }

        DB::transaction(function (): void {
            $this->generar();
        });
    }

    private function generar(): void
    {
        $hoy = now()->startOfDay();
        $empresa = Empresa::where('nombre', 'CREDIMAS')->firstOrFail();
        $agencia = Agencia::query()
            ->where('empresa_id', $empresa->id)
            ->where('nombre', 'Agencia Alameda')
            ->firstOrFail();

        $asesores = User::role('asesor')
            ->where('agencia_id', $agencia->id)
            ->orderBy('id')
            ->get();

        if ($asesores->isEmpty()) {
            throw new RuntimeException('No hay asesores de Agencia Alameda para generar el histórico de reportes.');
        }

        $admin = User::role('administrador_general')
            ->where('empresa_id', $empresa->id)
            ->firstOrFail();

        $supervisor = User::role('supervisor')
            ->where('agencia_id', $agencia->id)
            ->first();

        for ($anio = self::ANIO_INICIO; $anio <= (int) $hoy->year; $anio++) {
            $mesesDelAnio = $anio === (int) $hoy->year ? (int) $hoy->month : 12;

            for ($mes = 1; $mes <= $mesesDelAnio; $mes++) {
                /** @var array<int, CajaCiclo> $ciclos */
                $ciclos = [];

                foreach (range(0, 1) as $indice) {
                    $asesor = $asesores[$indice % $asesores->count()];
                    $fechaDesembolso = $this->fechaPreferida(
                        $anio,
                        $mes,
                        self::DIAS_DESEMBOLSO[$indice],
                        $hoy,
                    );
                    $fechaCobro = $this->fechaPreferida(
                        $anio,
                        $mes,
                        self::DIAS_COBRO[$indice],
                        $hoy,
                    );

                    if ($fechaCobro->lt($fechaDesembolso)) {
                        $fechaCobro = $fechaDesembolso->copy();
                    }

                    $monto = $this->montoDesembolso($mes, $indice);
                    $montoCobro = $this->montoCobro($mes, $indice);

                    $cliente = $this->crearCliente(
                        $empresa,
                        $agencia,
                        $asesor,
                        $anio,
                        $mes,
                        $indice,
                        $fechaDesembolso,
                    );
                    $bien = $this->crearGarantia($cliente, $asesor, $anio, $mes, $indice, $monto, $fechaDesembolso);
                    $credito = $this->crearCredito(
                        $empresa,
                        $agencia,
                        $cliente,
                        $bien,
                        $asesor,
                        $admin,
                        $supervisor,
                        $anio,
                        $mes,
                        $indice,
                        $monto,
                        $fechaDesembolso,
                        $hoy,
                    );
                    $this->crearCuota($credito, $monto, $fechaDesembolso->copy()->addDays(30));
                    [$ciclo, $cicloCreado] = $this->obtenerOCrearCiclo(
                        $asesor,
                        $admin,
                        $empresa,
                        $agencia,
                        $anio,
                        $mes,
                    );
                    if ($cicloCreado) {
                        $ciclos[$asesor->id] = $ciclo;
                    }

                    $this->crearDesembolso($credito, $ciclo, $asesor, $empresa, $monto, $fechaDesembolso);
                    $this->crearCobro(
                        $credito,
                        $cliente,
                        $ciclo,
                        $asesor,
                        $empresa,
                        $montoCobro,
                        $mes,
                        $indice,
                        $fechaCobro,
                    );
                }

                foreach ($ciclos as $ciclo) {
                    $this->cerrarCiclo($ciclo, $admin, $hoy);
                }
            }
        }
    }

    private function fechaPreferida(int $anio, int $mes, int $diaPreferido, Carbon $hoy): Carbon
    {
        $diasDelMes = Carbon::create($anio, $mes, 1)->daysInMonth;
        $dia = min($diaPreferido, $diasDelMes);

        if ($anio === (int) $hoy->year && $mes === (int) $hoy->month) {
            $dia = self::DIA_MES_EN_CURSO;
        }

        return Carbon::create($anio, $mes, $dia)->startOfDay();
    }

    private function montoDesembolso(int $mes, int $indice): float
    {
        return 600 + (($mes - 1) * 75) + ($indice * 175);
    }

    private function montoCobro(int $mes, int $indice): float
    {
        return 100 + ($mes * 15) + ($indice * 30);
    }

    private function crearCliente(
        Empresa $empresa,
        Agencia $agencia,
        User $asesor,
        int $anio,
        int $mes,
        int $indice,
        Carbon $fecha,
    ): Cliente {
        $numero = $indice + 1;
        $sufijo = sprintf('%02d-%d', $mes, $numero);
        $documento = "90{$anio}{$mes}{$numero}";
        $cliente = Cliente::query()->firstOrCreate([
            'empresa_id' => $empresa->id,
            'numero_documento' => $documento,
        ], [
            'agencia_id' => $agencia->id,
            'asesor_id' => $asesor->id,
            'registrado_por' => $asesor->id,
            'nombre' => 'Histórico',
            'apellido' => "Reporte {$sufijo}",
            'tipo_documento' => 'dni',
            'email' => "reporte.{$anio}.{$mes}.{$numero}@demo.local",
            'telefono' => '900000000',
            'direccion' => 'Dirección demo para reportes históricos',
            'referencia' => 'Cliente generado para los gráficos de cobranza.',
            'estado' => 'activo',
        ]);

        if ($cliente->wasRecentlyCreated) {
            $creado = $fecha->copy()->subDays(2)->setTime(9, 0);
            $cliente->forceFill([
                'created_at' => $creado,
                'updated_at' => $creado,
            ])->saveQuietly();
        }

        return $cliente->fresh();
    }

    private function crearGarantia(
        Cliente $cliente,
        User $asesor,
        int $anio,
        int $mes,
        int $indice,
        float $monto,
        Carbon $fecha,
    ): Bien {
        $sufijo = sprintf('%02d-%d', $mes, $indice + 1);
        $serie = "HIST-{$anio}-{$sufijo}";
        $valorizacion = $monto + 800;
        $precioVenta = $monto + 200;
        $precioOferta = $monto + 100;
        $bien = Bien::query()->firstOrCreate([
            'cliente_id' => $cliente->id,
            'serie' => $serie,
        ], [
            'empresa_id' => $cliente->empresa_id,
            'agencia_id' => $cliente->agencia_id,
            'registrado_por' => $asesor->id,
            'codigo' => "BH-{$anio}-{$sufijo}",
            'tipo' => 'electro',
            'nombre' => 'Laptop histórica',
            'marca' => 'HP',
            'modelo' => 'ProBook',
            'observacion' => 'Garantía de demostración para reportes históricos.',
            'valorizacion' => $valorizacion,
            'precio_venta' => $precioVenta,
            'precio_oferta' => $precioOferta,
            'puntaje' => 8,
            'estado' => 'en_garantia',
        ]);

        if ($bien->wasRecentlyCreated) {
            $creado = $fecha->copy()->subDay()->setTime(10, 0);
            $bien->forceFill([
                'created_at' => $creado,
                'updated_at' => $creado,
            ])->saveQuietly();
        }

        if (blank($bien->codigo)) {
            $bien->forceFill([
                'codigo' => "BH-{$anio}-{$sufijo}",
            ])->saveQuietly();
        }

        return $bien->fresh();
    }

    private function crearCredito(
        Empresa $empresa,
        Agencia $agencia,
        Cliente $cliente,
        Bien $bien,
        User $asesor,
        User $admin,
        ?User $supervisor,
        int $anio,
        int $mes,
        int $indice,
        float $monto,
        Carbon $fechaDesembolso,
        Carbon $hoy,
    ): Credito {
        $sufijo = sprintf('%02d-%d', $mes, $indice + 1);
        $codigo = "CH-{$anio}-{$sufijo}";
        $fechaVencimiento = $fechaDesembolso->copy()->addDays(30);
        $estado = $fechaVencimiento->lt($hoy) ? 'vencido' : 'activo';
        $credito = Credito::query()->firstOrCreate([
            'empresa_id' => $empresa->id,
            'codigo' => $codigo,
        ], [
            'agencia_id' => $agencia->id,
            'tipo_credito' => 'prendario',
            'cliente_id' => $cliente->id,
            'registrado_por' => $asesor->id,
            'supervisado_por' => $supervisor?->id,
            'numero_refrendo' => 0,
            'monto_prestamo' => $monto,
            'interes' => 15,
            'tipo_interes' => 'simple',
            'interes_solicitud_especial' => false,
            'tipo_cuota' => 'mensual',
            'numero_cuotas' => 1,
            'plazo_dias' => 30,
            'estado' => $estado,
            'aprobado_por' => $admin->id,
            'fecha_aprobacion' => $fechaDesembolso->copy()->subDay()->setTime(11, 0),
            'fecha_desembolso' => $fechaDesembolso->toDateString(),
            'fecha_vencimiento' => $fechaVencimiento->toDateString(),
        ]);

        $credito->forceFill([
            'fecha_desembolso' => $fechaDesembolso->toDateString(),
            'fecha_vencimiento' => $fechaVencimiento->toDateString(),
            'estado' => $estado,
        ])->saveQuietly();

        if ($credito->wasRecentlyCreated) {
            $creado = $fechaDesembolso->copy()->subDays(3)->setTime(9, 0);
            $credito->forceFill([
                'created_at' => $creado,
                'updated_at' => $creado,
            ])->saveQuietly();
        }

        $credito->bienes()->syncWithoutDetaching([$bien->id]);

        return $credito->fresh();
    }

    private function crearCuota(Credito $credito, float $monto, Carbon $fechaVencimiento): CuotaCredito
    {
        $interes = round($monto * 0.15, 2);
        $total = round($monto + $interes, 2);
        $cuota = CuotaCredito::query()->firstOrCreate([
            'credito_id' => $credito->id,
            'numero_cuota' => 1,
        ], [
            'empresa_id' => $credito->empresa_id,
            'fecha_vencimiento' => $fechaVencimiento->toDateString(),
            'monto_capital' => $monto,
            'monto_interes' => $interes,
            'monto_total' => $total,
            'monto_abonado' => 0,
        ]);

        if ($cuota->wasRecentlyCreated) {
            $creado = $cuota->fecha_vencimiento->copy()->subDays(3)->setTime(9, 0);
            $cuota->forceFill([
                'created_at' => $creado,
                'updated_at' => $creado,
            ])->saveQuietly();
        }

        return $cuota->fresh();
    }

    /**
     * @return array{0: CajaCiclo, 1: bool}
     */
    private function obtenerOCrearCiclo(
        User $asesor,
        User $admin,
        Empresa $empresa,
        Agencia $agencia,
        int $anio,
        int $mes,
    ): array {
        $caja = Caja::query()->firstOrCreate([
            'user_id' => $asesor->id,
        ], [
            'empresa_id' => $empresa->id,
            'agencia_id' => $agencia->id,
        ]);

        $fecha = Carbon::create($anio, $mes, 1)->startOfDay();
        $ciclo = CajaCiclo::query()
            ->where('caja_id', $caja->id)
            ->whereDate('fecha', $fecha->toDateString())
            ->where('saldo_apertura', self::SALDO_APERTURA_HISTORICO)
            ->whereTime('abierta_at', '08:00:00')
            ->first();
        $cicloCreado = false;

        if ($ciclo === null) {
            $cicloCreado = true;
            $ciclo = CajaCiclo::query()->create([
                'caja_id' => $caja->id,
                'empresa_id' => $empresa->id,
                'fecha' => $fecha->toDateString(),
                'estado' => 'abierta',
                'saldo_apertura' => self::SALDO_APERTURA_HISTORICO,
                'abierta_at' => $fecha->copy()->setTime(8, 0),
            ]);
        } elseif ($ciclo->estado === 'abierta') {
            // Si una corrida anterior se interrumpió, el ciclo del seed
            // puede haber quedado abierto; lo cerramos al finalizar.
            $cicloCreado = true;
        }

        if ($ciclo->wasRecentlyCreated) {
            $ciclo->forceFill([
                'created_at' => $fecha->copy()->setTime(8, 0),
                'updated_at' => $fecha->copy()->setTime(8, 0),
            ])->saveQuietly();
        }

        return [$ciclo->fresh(), $cicloCreado];
    }

    private function crearDesembolso(
        Credito $credito,
        CajaCiclo $ciclo,
        User $asesor,
        Empresa $empresa,
        float $monto,
        Carbon $fecha,
    ): CajaMovimiento {
        $concepto = "Desembolso histórico #{$credito->codigo}";
        $movimiento = CajaMovimiento::query()
            ->where('caja_ciclo_id', $ciclo->id)
            ->where('tipo', 'egreso')
            ->whereDate('fecha_caja', $fecha->toDateString())
            ->where(function ($query) use ($credito, $concepto): void {
                $query->where('credito_id', $credito->id)
                    ->orWhere('concepto', $concepto);
            })
            ->first();

        if ($movimiento === null) {
            $movimiento = CajaMovimiento::query()->create([
                'caja_ciclo_id' => $ciclo->id,
                'empresa_id' => $empresa->id,
                'credito_id' => $credito->id,
                'tipo' => 'egreso',
                'monto' => $monto,
                'medio' => 'efectivo',
                'fecha_caja' => $fecha->toDateString(),
                'concepto' => $concepto,
                'registrado_por' => $asesor->id,
            ]);
        } elseif ($movimiento->credito_id === null) {
            $movimiento->forceFill(['credito_id' => $credito->id])->saveQuietly();
        }

        if ($movimiento->wasRecentlyCreated) {
            $creado = $fecha->copy()->setTime(10, 0);
            $movimiento->forceFill([
                'created_at' => $creado,
                'updated_at' => $creado,
            ])->saveQuietly();
        }

        return $movimiento->fresh();
    }

    private function crearCobro(
        Credito $credito,
        Cliente $cliente,
        CajaCiclo $ciclo,
        User $asesor,
        Empresa $empresa,
        float $monto,
        int $mes,
        int $indice,
        Carbon $fecha,
    ): Cobro {
        $medio = self::MEDIOS_COBRO[($mes + $indice) % count(self::MEDIOS_COBRO)];
        $concepto = "Cobro histórico #{$credito->codigo}";
        $movimiento = CajaMovimiento::query()
            ->where('caja_ciclo_id', $ciclo->id)
            ->where('tipo', 'ingreso')
            ->where('concepto', $concepto)
            ->whereDate('fecha_caja', $fecha->toDateString())
            ->first();

        if ($movimiento === null) {
            $movimiento = CajaMovimiento::query()->create([
                'caja_ciclo_id' => $ciclo->id,
                'empresa_id' => $empresa->id,
                'tipo' => 'ingreso',
                'monto' => $monto,
                'medio' => $medio,
                'fecha_caja' => $fecha->toDateString(),
                'concepto' => $concepto,
                'registrado_por' => $asesor->id,
            ]);
        }

        if ($movimiento->wasRecentlyCreated) {
            $creado = $fecha->copy()->setTime(15, 0);
            $movimiento->forceFill([
                'created_at' => $creado,
                'updated_at' => $creado,
            ])->saveQuietly();
        }

        $cobro = Cobro::query()
            ->where('caja_ciclo_id', $ciclo->id)
            ->where('credito_id', $credito->id)
            ->where('monto_pagado', $monto)
            ->whereDate('created_at', $fecha->toDateString())
            ->first();
        $cobroNuevo = false;

        if ($cobro === null) {
            $cobro = Cobro::query()->create([
                'empresa_id' => $empresa->id,
                'cliente_id' => $cliente->id,
                'credito_id' => $credito->id,
                'caja_ciclo_id' => $ciclo->id,
                'caja_movimiento_id' => $movimiento->id,
                'registrado_por' => $asesor->id,
                'operacion' => 'pago_cuotas_diario',
                'estado' => 'registrado',
                'credito_estado_anterior' => $credito->estado,
                'monto_pagado' => $monto,
                'medio' => $medio,
                'interes' => $monto,
                'mora' => 0,
                'descuento' => 0,
                'vuelto' => 0,
            ]);

            $cobro->forceFill([
                'created_at' => $fecha->copy()->setTime(15, 0),
                'updated_at' => $fecha->copy()->setTime(15, 0),
            ])->saveQuietly();
            $cobroNuevo = true;
        } elseif ($cobro->caja_movimiento_id === null) {
            $cobro->forceFill(['caja_movimiento_id' => $movimiento->id])->saveQuietly();
        }

        if ($cobroNuevo) {
            $cuota = $credito->cuotas()->where('numero_cuota', 1)->first();

            if ($cuota !== null) {
                $montoAbonado = min($monto, (float) $cuota->monto_total);
                $totalAbonado = round((float) $cuota->monto_abonado + $montoAbonado, 2);
                $cuota->forceFill([
                    'monto_abonado' => $totalAbonado,
                    'mora_pagada' => 0,
                ])->saveQuietly();

                CobroCuotaAbono::query()->firstOrCreate([
                    'cobro_id' => $cobro->id,
                    'cuota_credito_id' => $cuota->id,
                ], [
                    'monto_mora' => 0,
                    'monto_cuota' => $montoAbonado,
                    'completa' => $totalAbonado >= (float) $cuota->monto_total,
                ]);
            }
        }

        return $cobro->fresh();
    }

    private function cerrarCiclo(CajaCiclo $ciclo, User $admin, Carbon $hoy): void
    {
        $ciclo = $ciclo->fresh();
        $saldo = $ciclo->saldoActual();
        $saldoEfectivo = $ciclo->saldoEfectivo();
        $fechaCierre = $ciclo->fecha->copy()->endOfMonth();

        if ($ciclo->fecha->isSameMonth($hoy) && $ciclo->fecha->year === $hoy->year) {
            $fechaCierre = $hoy->copy()->endOfDay();
        }

        $ciclo->forceFill([
            'estado' => 'cerrada',
            'saldo_calculado_cierre' => $saldo,
            'saldo_efectivo_cierre' => $saldoEfectivo,
            'saldo_arqueo_cierre' => $saldoEfectivo,
            'diferencia' => 0,
            'cerrada_at' => $fechaCierre,
            'cerrada_por' => $admin->id,
            'updated_at' => $fechaCierre,
        ])->saveQuietly();
    }
}
