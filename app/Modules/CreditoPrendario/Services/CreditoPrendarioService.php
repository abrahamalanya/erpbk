<?php

namespace App\Modules\CreditoPrendario\Services;

use App\Modules\Caja\Events\CajaActualizada;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\CreditoPrendario\Events\CreditoPrendarioActualizado;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\CreditoPrendario\Models\CuotaCreditoPrendario;
use App\Modules\CreditoPrendario\Notifications\CreditoAprobacionRevertidaNotification;
use App\Modules\CreditoPrendario\Notifications\CreditoAprobadoNotification;
use App\Modules\CreditoPrendario\Notifications\CreditoDesembolsadoNotification;
use App\Modules\CreditoPrendario\Notifications\CreditoRechazadoNotification;
use App\Modules\CreditoPrendario\Notifications\CreditoSolicitadoNotification;
use App\Modules\CreditoPrendario\Notifications\CreditoSubsanadoNotification;
use App\Modules\Sistemas\Services\NotificacionService;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CreditoPrendarioService
{
    /**
     * Default número de cuotas per tipo_cuota, used when desembolsar() isn't
     * given an explicit override — a fixed table, not derived from
     * plazo_dias (confirmed explicitly with the user).
     *
     * @var array<string, int>
     */
    private const CUOTAS_POR_TIPO = [
        'diario' => 30,
        'semanal' => 4,
        'quincenal' => 2,
        'mensual' => 1,
    ];

    /**
     * Fixed period length in days for ONE cuota of each tipo_cuota,
     * independent of plazo_dias — `interes` is configured as a single
     * monthly-basis rate, and each period's interest is that rate prorated
     * to the period's length (same day-based formula as
     * calcularMontoLiquidacion(), just evaluated at a fixed period length
     * instead of days elapsed). Choosing more cuotas than the default
     * EXTENDS the crédito's real term — confirmed explicitly: each cuota is
     * a full period, not a subdivision of the original plazo_dias.
     *
     * @var array<string, int>
     */
    private const DIAS_POR_PERIODO = [
        'diario' => 1,
        'semanal' => 7,
        'quincenal' => 15,
        'mensual' => 30,
    ];

    public function __construct(
        private readonly ConfiguracionCreditoPrendarioService $configuracion,
        private readonly DocumentoCreditoPrendarioService $documentos,
        private readonly CreditoPrendarioHierarchyService $hierarchy,
        private readonly NotificacionService $notificaciones,
    ) {}

    /**
     * @param  Collection<int, Bien>  $bienes
     * @param  array{monto_prestamo: string, interes?: string, tipo_cuota: string}  $datos
     */
    public function registrar(User $actor, Collection $bienes, array $datos): CreditoPrendario
    {
        $caja = Caja::query()->where('user_id', $actor->id)->first();

        if (! $caja?->cicloAbierto()->exists()) {
            throw new DomainException('Debes aperturar tu caja antes de registrar un crédito.');
        }

        if ($bienes->isEmpty()) {
            throw new DomainException('Debes seleccionar al menos un bien.');
        }

        if ($bienes->pluck('cliente_id')->unique()->count() > 1) {
            throw new DomainException('Todos los bienes deben pertenecer al mismo cliente.');
        }

        $bienIds = $bienes->pluck('id');

        if (Bien::disponibles()->whereIn('id', $bienIds)->count() !== $bienIds->count()) {
            throw new DomainException('Uno o más bienes seleccionados ya están respaldando otro crédito activo.');
        }

        $sumaValorizaciones = $bienes->reduce(
            fn (string $carry, Bien $bien): string => bcadd($carry, (string) $bien->valorizacion, 2),
            '0'
        );

        if (bccomp($datos['monto_prestamo'], $sumaValorizaciones, 2) > 0) {
            throw new DomainException('El monto del préstamo no puede superar la suma de las valorizaciones de los bienes seleccionados.');
        }

        $primerBien = $bienes->first();
        $configuracion = $this->configuracion->resolverPara($primerBien->agencia);

        return DB::transaction(function () use ($actor, $bienIds, $primerBien, $datos, $configuracion): CreditoPrendario {
            $credito = CreditoPrendario::query()->create([
                'empresa_id' => $primerBien->empresa_id,
                'agencia_id' => $primerBien->agencia_id,
                'cliente_id' => $primerBien->cliente_id,
                'registrado_por' => $actor->id,
                'numero_refrendo' => 0,
                'monto_prestamo' => $datos['monto_prestamo'],
                'interes' => $datos['interes'] ?? $configuracion->interes_default,
                'tipo_cuota' => $datos['tipo_cuota'],
                'plazo_dias' => $configuracion->plazo_dias,
                'estado' => 'pendiente',
            ]);

            $credito->bienes()->attach($bienIds);
            Bien::query()->whereIn('id', $bienIds)->update(['estado' => 'en_garantia']);

            $credito = $credito->fresh(['bienes']);
            $this->notificar($credito);
            $this->notificaciones->enviar($this->hierarchy->controladoresDe($credito), new CreditoSolicitadoNotification($credito));

            return $credito;
        });
    }

    public function aprobar(CreditoPrendario $credito, User $aprobador): CreditoPrendario
    {
        $this->asegurarEstado($credito, 'pendiente');

        return DB::transaction(function () use ($credito, $aprobador): CreditoPrendario {
            $credito->update([
                'estado' => 'aprobado',
                'aprobado_por' => $aprobador->id,
                'fecha_aprobacion' => now(),
            ]);

            if ($credito->numero_refrendo === 0) {
                $this->documentos->generarContrato($credito, $aprobador);
                $this->documentos->generarDeclaracion($credito, $aprobador);
            }

            $credito = $credito->fresh();
            $this->notificar($credito);
            $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoAprobadoNotification($credito));

            return $credito;
        });
    }

    public function rechazar(CreditoPrendario $credito, User $aprobador, string $motivo): CreditoPrendario
    {
        $this->asegurarEstado($credito, 'pendiente');

        $credito->update([
            'estado' => 'rechazado',
            'aprobado_por' => $aprobador->id,
            'motivo_rechazo' => $motivo,
            'fecha_aprobacion' => now(),
        ]);

        $credito = $credito->fresh();
        $this->notificar($credito);
        $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoRechazadoNotification($credito));

        return $credito;
    }

    /**
     * Sends a rechazado crédito back to pendiente so it re-enters the review
     * queue — used once the asesor has fixed whatever the motivo_rechazo
     * pointed out (e.g. missing photos on a bien). motivo_rechazo is kept
     * as-is (not cleared) so the reviewer still sees why it was rejected
     * last time; it'll simply be overwritten if rejected again.
     */
    public function subsanar(CreditoPrendario $credito, User $actor): CreditoPrendario
    {
        $this->asegurarEstado($credito, 'rechazado');

        $bienIds = $credito->bienes->pluck('id');

        if (Bien::disponibles()->whereIn('id', $bienIds)->count() !== $bienIds->count()) {
            throw new DomainException('Uno o más bienes de este crédito ya están respaldando otro crédito activo.');
        }

        $credito->update(['estado' => 'pendiente']);

        $credito = $credito->fresh();
        $this->notificar($credito);
        $this->notificaciones->enviar($this->hierarchy->controladoresDe($credito), new CreditoSubsanadoNotification($credito));

        return $credito;
    }

    /**
     * Undoes an accidental aprobar() — back to pendiente, clearing
     * aprobado_por/fecha_aprobacion so a subsequent aprobar() sets them
     * fresh. Only while still 'aprobado': once firmado, fecha_desembolso/
     * fecha_vencimiento are already computed and real disbursement may have
     * happened, so there's nothing sensible left to revert.
     */
    public function revertirAprobacion(CreditoPrendario $credito, User $actor): CreditoPrendario
    {
        $this->asegurarEstado($credito, 'aprobado');

        $credito->update([
            'estado' => 'pendiente',
            'aprobado_por' => null,
            'fecha_aprobacion' => null,
        ]);

        $credito = $credito->fresh();
        $this->notificar($credito);
        $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoAprobacionRevertidaNotification($credito));

        return $credito;
    }

    /**
     * Lets an admin override the interest rate for exceptional cases (e.g.
     * an exclusive client on a custom rate) — only before the crédito is
     * firmado, since fecha_desembolso/fecha_vencimiento and any disbursed
     * cash are already locked in past that point.
     */
    public function actualizarInteres(CreditoPrendario $credito, User $actor, string $interes): CreditoPrendario
    {
        if (! in_array($credito->estado, ['pendiente', 'aprobado'], true)) {
            throw new DomainException('Solo se puede editar la tasa de interés mientras el crédito está pendiente o aprobado.');
        }

        $credito->update(['interes' => $interes]);

        $credito = $credito->fresh();
        $this->notificar($credito);

        return $credito;
    }

    /**
     * Activates the crédito, moves the cash out of the actor's own caja, and
     * generates the cuotas cronograma. Replaces the old firmar() confirm-
     * button — signing is now proven by each documento's uploaded scan
     * (DocumentoCreditoPrendarioService::subirFirmado()), so this only needs
     * to check that every documento already has a firmado_at.
     *
     * plazo_dias/fecha_vencimiento are computed HERE (not from the config
     * value snapshotted at registrar() time) as número de cuotas × the
     * tipo_cuota's fixed period length — confirmed explicitly: choosing
     * more cuotas than the default extends the crédito's real term (e.g. 2
     * cuotas mensuales = 60 días reales, not 2 checkpoints inside the same
     * 30 días).
     */
    public function desembolsar(CreditoPrendario $credito, User $actor, ?int $numeroCuotas, ?string $interes): CreditoPrendario
    {
        $this->asegurarEstado($credito, 'aprobado');

        if ($credito->documentos()->whereNull('firmado_at')->exists()) {
            throw new DomainException('Todos los documentos deben estar firmados (subir el escaneo firmado) antes de desembolsar.');
        }

        $caja = Caja::query()->where('user_id', $actor->id)->first();
        $ciclo = $caja?->cicloAbierto()->first();

        if (! $ciclo) {
            throw new DomainException('Debes aperturar tu caja antes de desembolsar.');
        }

        if (bccomp($credito->monto_prestamo, $ciclo->saldoActual(), 2) > 0) {
            throw new DomainException('No tienes saldo suficiente en tu caja para desembolsar este crédito.');
        }

        return DB::transaction(function () use ($credito, $actor, $ciclo, $numeroCuotas, $interes): CreditoPrendario {
            if ($interes !== null) {
                $credito->update(['interes' => $interes]);
            }

            $n = $numeroCuotas ?? self::CUOTAS_POR_TIPO[$credito->tipo_cuota];
            $diasPorCuota = self::DIAS_POR_PERIODO[$credito->tipo_cuota];
            $plazoTotal = $diasPorCuota * $n;

            $fechaDesembolso = now()->startOfDay();

            $credito->update([
                'estado' => 'activo',
                'fecha_desembolso' => $fechaDesembolso->toDateString(),
                'plazo_dias' => $plazoTotal,
                'fecha_vencimiento' => $fechaDesembolso->copy()->addDays($plazoTotal)->toDateString(),
            ]);

            CajaMovimiento::query()->create([
                'caja_ciclo_id' => $ciclo->id,
                'empresa_id' => $ciclo->empresa_id,
                'tipo' => 'egreso',
                'monto' => $credito->monto_prestamo,
                'concepto' => "Desembolso de crédito prendario #{$credito->id}",
                'registrado_por' => $actor->id,
                'fecha_caja' => $ciclo->fecha,
            ]);

            CajaActualizada::dispatch($ciclo->caja, $ciclo->fresh()->saldoActual());

            $credito = $credito->fresh();
            $this->generarCronograma($credito, $n, $diasPorCuota);
            $this->notificar($credito);
            $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoDesembolsadoNotification($credito));

            return $credito;
        });
    }

    /**
     * Each cuota is an independent interest-only charge for one full
     * period of the crédito's tipo_cuota — capital stays whole until the
     * crédito is liquidado (same shape as refrendar(): pay interest, keep
     * the pledge). The formula mirrors calcularMontoLiquidacion() exactly,
     * evaluated at a fixed period length instead of días transcurridos, so
     * `interes` stays a single monthly-basis rate with no config changes
     * needed — a semanal/diario/quincenal cuota is just that same rate
     * prorated to a shorter period.
     */
    private function generarCronograma(CreditoPrendario $credito, int $n, int $diasPorCuota): void
    {
        $factor = bcmul((string) $credito->monto_prestamo, (string) $credito->interes, 10);
        $interesPorCuota = bcdiv(bcmul($factor, (string) $diasPorCuota, 10), '3000', 2);

        for ($i = 1; $i <= $n; $i++) {
            CuotaCreditoPrendario::query()->create([
                'credito_id' => $credito->id,
                'empresa_id' => $credito->empresa_id,
                'numero_cuota' => $i,
                'fecha_vencimiento' => $credito->fecha_desembolso->copy()->addDays($diasPorCuota * $i),
                'monto_capital' => '0.00',
                'monto_interes' => $interesPorCuota,
                'monto_total' => $interesPorCuota,
            ]);
        }
    }

    public function refrendar(CreditoPrendario $credito, User $actor, string $montoInteresPagado): CreditoPrendario
    {
        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede refrendar un crédito activo o vencido.');
        }

        if (bccomp($montoInteresPagado, '0', 2) <= 0) {
            throw new DomainException('El monto de interés pagado debe ser mayor a cero.');
        }

        $configuracion = $this->configuracion->resolverPara($credito->agencia);
        $siguienteNumero = $credito->numero_refrendo + 1;

        if ($configuracion->max_refrendos !== null && $siguienteNumero > $configuracion->max_refrendos) {
            throw new DomainException("Este crédito ya alcanzó el máximo de {$configuracion->max_refrendos} refrendos permitidos; debe liquidarse el capital.");
        }

        return DB::transaction(function () use ($credito, $actor, $siguienteNumero, $configuracion): CreditoPrendario {
            $credito->update(['estado' => 'refrendado']);

            $fechaDesembolso = now()->startOfDay();

            $nuevo = CreditoPrendario::query()->create([
                'empresa_id' => $credito->empresa_id,
                'agencia_id' => $credito->agencia_id,
                'cliente_id' => $credito->cliente_id,
                'registrado_por' => $actor->id,
                'refrendo_de_credito_id' => $credito->id,
                'numero_refrendo' => $siguienteNumero,
                'monto_prestamo' => $credito->monto_prestamo,
                'interes' => $credito->interes,
                'tipo_cuota' => $credito->tipo_cuota,
                'plazo_dias' => $configuracion->plazo_dias,
                'estado' => 'activo',
                'fecha_desembolso' => $fechaDesembolso->toDateString(),
                'fecha_vencimiento' => $fechaDesembolso->copy()->addDays($configuracion->plazo_dias)->toDateString(),
            ]);

            $nuevo->bienes()->attach($credito->bienes->pluck('id'));

            $this->documentos->generarAdenda($nuevo, $actor);

            $nuevo = $nuevo->fresh(['bienes']);
            $this->notificar($nuevo);

            return $nuevo;
        });
    }

    public function liquidar(CreditoPrendario $credito, User $actor, string $montoPagado): CreditoPrendario
    {
        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede liquidar un crédito activo o vencido.');
        }

        $montoCalculado = $this->calcularMontoLiquidacion($credito)['total'];

        if (bccomp($montoPagado, $montoCalculado, 2) < 0) {
            throw new DomainException("El monto pagado ({$montoPagado}) es menor al monto a liquidar calculado ({$montoCalculado}).");
        }

        return DB::transaction(function () use ($credito): CreditoPrendario {
            $credito->update(['estado' => 'liquidado']);
            Bien::query()->whereIn('id', $credito->bienes->pluck('id'))->update(['estado' => 'recuperado']);

            $credito = $credito->fresh(['bienes']);
            $this->notificar($credito);

            return $credito;
        });
    }

    /**
     * Interest owed to liquidar today: prorated by days elapsed since
     * fecha_desembolso, with a configurable minimum floor (so paying off
     * early still charges at least N days of interest) — confirmed with the
     * user via a worked example: monto_prestamo=1000, interés=20% mensual,
     * mínimo=15 días; canceling at day 5 still charges as if 15 days passed,
     * canceling at day 17 charges for the actual 17.
     *
     * @return array{capital: string, interes: string, total: string, dias_transcurridos: int, dias_minimo: int, dias_cobrados: int, tasa_interes: string}
     */
    public function calcularMontoLiquidacion(CreditoPrendario $credito): array
    {
        $configuracion = $this->configuracion->resolverPara($credito->agencia);

        $diasTranscurridos = max(0, (int) $credito->fecha_desembolso->copy()->startOfDay()->diffInDays(now()->startOfDay()));
        $diasCobrables = max($diasTranscurridos, $configuracion->dias_minimo_interes);

        // Un solo bcdiv al final (no una tasa diaria redondeada intermedia)
        // para no arrastrar error de truncamiento en cada paso.
        $factor = bcmul((string) $credito->monto_prestamo, (string) $credito->interes, 10);
        $factor = bcmul($factor, (string) $diasCobrables, 10);
        $interes = bcdiv($factor, '3000', 2); // /100 (%) /30 (días del mes)

        return [
            'capital' => (string) $credito->monto_prestamo,
            'interes' => $interes,
            'total' => bcadd((string) $credito->monto_prestamo, $interes, 2),
            'dias_transcurridos' => $diasTranscurridos,
            'dias_minimo' => $configuracion->dias_minimo_interes,
            'dias_cobrados' => $diasCobrables,
            'tasa_interes' => (string) $credito->interes,
        ];
    }

    /**
     * Daily state transitions: activo -> vencido once fecha_vencimiento passes,
     * vencido -> en_venta once the configured días de espera also pass.
     */
    public function actualizarEstadosVencidos(): void
    {
        $hoy = now()->startOfDay()->toDateString();

        CreditoPrendario::query()
            ->where('estado', 'activo')
            ->whereDate('fecha_vencimiento', '<', $hoy)
            ->update(['estado' => 'vencido']);

        CreditoPrendario::query()
            ->where('estado', 'vencido')
            ->with(['bienes', 'agencia'])
            ->get()
            ->each(function (CreditoPrendario $credito) use ($hoy): void {
                $configuracion = $this->configuracion->resolverPara($credito->agencia);
                $fechaLimite = $credito->fecha_vencimiento->copy()->addDays($configuracion->dias_espera_mora)->toDateString();

                if ($fechaLimite < $hoy) {
                    $credito->update(['estado' => 'en_venta']);
                    Bien::query()->whereIn('id', $credito->bienes->pluck('id'))->update(['estado' => 'disponible_venta']);
                }
            });
    }

    public function calcularMora(CreditoPrendario $credito): string
    {
        $dias = $credito->dias_en_mora;

        if ($dias === 0) {
            return '0.00';
        }

        $configuracion = $this->configuracion->resolverPara($credito->agencia);
        $tasaDiaria = bcdiv((string) $configuracion->tasa_mora_diaria, '100', 4);

        return bcmul(bcmul((string) $credito->monto_prestamo, $tasaDiaria, 4), (string) $dias, 2);
    }

    private function asegurarEstado(CreditoPrendario $credito, string $esperado): void
    {
        if ($credito->estado !== $esperado) {
            throw new DomainException("El crédito debe estar en estado '{$esperado}' para esta acción (actual: '{$credito->estado}').");
        }
    }

    /**
     * Broadcasts the crédito's current state to the asesor who registered
     * it and to whoever currently has authority to aprobar/rechazar it —
     * so both sides see solicitar/aprobar/rechazar/subsanar live, without
     * either having to leave and re-enter the module.
     */
    private function notificar(CreditoPrendario $credito): void
    {
        $destinatarios = $this->hierarchy->controladoresDe($credito)
            ->push($credito->registradoPor);

        CreditoPrendarioActualizado::dispatch($credito, $destinatarios);
    }
}
