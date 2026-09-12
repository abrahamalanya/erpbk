<?php

namespace App\Modules\Credito\Services;

use App\Modules\Caja\Events\CajaActualizada;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Events\CreditoActualizado;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Credito\Models\DocumentoCredito;
use App\Modules\Credito\Notifications\CreditoAdendadoNotification;
use App\Modules\Credito\Notifications\CreditoAprobacionRevertidaNotification;
use App\Modules\Credito\Notifications\CreditoAprobadoNotification;
use App\Modules\Credito\Notifications\CreditoConformidadRegistradaNotification;
use App\Modules\Credito\Notifications\CreditoDesembolsadoNotification;
use App\Modules\Credito\Notifications\CreditoEnVentaNotification;
use App\Modules\Credito\Notifications\CreditoFechaDesembolsoActualizadaNotification;
use App\Modules\Credito\Notifications\CreditoInteresActualizadoNotification;
use App\Modules\Credito\Notifications\CreditoLiquidadoNotification;
use App\Modules\Credito\Notifications\CreditoNumeroCuotasActualizadoNotification;
use App\Modules\Credito\Notifications\CreditoPendienteConformidadNotification;
use App\Modules\Credito\Notifications\CreditoRechazadoNotification;
use App\Modules\Credito\Notifications\CreditoRefinanciadoNotification;
use App\Modules\Credito\Notifications\CreditoRefrendadoNotification;
use App\Modules\Credito\Notifications\CreditoSolicitadoNotification;
use App\Modules\Credito\Notifications\CreditoSubsanadoNotification;
use App\Modules\Credito\Notifications\CreditoVencidoNotification;
use App\Modules\Credito\Tipos\CreditoTipoManager;
use App\Modules\Sistemas\Services\NotificacionService;
use App\Modules\Usuario\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CreditoService
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
        private readonly ConfiguracionCreditoService $configuracion,
        private readonly DocumentoCreditoService $documentos,
        private readonly CreditoHierarchyService $hierarchy,
        private readonly NotificacionService $notificaciones,
        private readonly CreditoTipoManager $tipos,
    ) {}

    /**
     * The garantía relation for this crédito, resolved to the concrete model
     * (Bien / Vehiculo / …) of its tipo. Use instead of $credito->bienes so
     * the engine stays tipo-agnostic.
     */
    private function garantiasDe(Credito $credito): MorphToMany
    {
        return $credito->garantiasComo($this->tipos->paraCredito($credito)->garantiaModelo());
    }

    /**
     * @param  Collection<int, Model>  $garantias  Bien / Vehiculo / Inmueble instances (all of the tipo's garantiaModelo)
     * @param  array{monto_prestamo: string, interes?: string, interes_solicitud_especial?: bool, motivo_interes?: string, tipo_cuota: string, numero_cuotas?: int|null, supervisado_por?: int}  $datos
     */
    public function registrar(User $actor, Collection $garantias, array $datos, string $tipoClave = 'prendario'): Credito
    {
        $tipo = $this->tipos->para($tipoClave);
        $modeloGarantia = $tipo->garantiaModelo();

        $caja = Caja::query()->where('user_id', $actor->id)->first();

        if (! $caja?->cicloAbierto()->exists()) {
            throw new DomainException('Debes aperturar tu caja antes de registrar un crédito.');
        }

        if ($garantias->isEmpty()) {
            throw new DomainException('Debes seleccionar al menos una garantía.');
        }

        $maxGarantias = $tipo->maxGarantias();

        if ($maxGarantias !== null && $garantias->count() > $maxGarantias) {
            throw new DomainException("Este tipo de crédito admite como máximo {$maxGarantias} garantía(s).");
        }

        if ($garantias->pluck('cliente_id')->unique()->count() > 1) {
            throw new DomainException('Todas las garantías deben pertenecer al mismo cliente.');
        }

        $garantiaIds = $garantias->pluck('id');

        if ($modeloGarantia::query()->disponibles()->whereIn('id', $garantiaIds)->count() !== $garantiaIds->count()) {
            throw new DomainException('Una o más garantías seleccionadas ya están respaldando otro crédito activo.');
        }

        $sumaValorizaciones = $garantias->reduce(
            fn (string $carry, $garantia): string => bcadd($carry, (string) $garantia->valorizacion, 2),
            '0'
        );

        if (bccomp($datos['monto_prestamo'], $sumaValorizaciones, 2) > 0) {
            throw new DomainException('El monto del préstamo no puede superar la suma de las valorizaciones de las garantías seleccionadas.');
        }

        $primera = $garantias->first();
        $tipo->validarRegistro($actor, $garantias, $primera->cliente, $datos);

        $configuracion = $this->configuracion->resolverPara($primera->agencia, $tipoClave);

        $interesDifiereDelDefault = isset($datos['interes'])
            && bccomp((string) $datos['interes'], (string) $configuracion->interes_default, 2) !== 0;

        if ($interesDifiereDelDefault && blank($datos['motivo_interes'] ?? null)) {
            throw new DomainException('Debes explicar el motivo cuando la tasa de interés difiere de la configurada por defecto.');
        }

        // Número de cuotas: opcional y solo aplica cuando la config del tipo
        // permite más de una (vehicular / hipotecario). Si el asesor no lo
        // elige — o el tipo no lo permite — queda null: el crédito conserva el
        // plazo de la config y al desembolsar cae al default por tipo_cuota
        // (CUOTAS_POR_TIPO), comportamiento clásico de prendario. Si lo elige,
        // el plazo se deriva de él (n × período) ya desde el registro para que
        // el contrato refleje el plazo real.
        $numeroCuotas = null;

        if ($configuracion->max_cuotas > 1 && isset($datos['numero_cuotas']) && $datos['numero_cuotas'] !== null) {
            $numeroCuotas = (int) $datos['numero_cuotas'];

            if ($numeroCuotas < 1 || $numeroCuotas > $configuracion->max_cuotas) {
                throw new DomainException("El número de cuotas debe estar entre 1 y {$configuracion->max_cuotas} para este tipo de crédito.");
            }
        }

        $plazoDias = $numeroCuotas !== null
            ? self::DIAS_POR_PERIODO[$datos['tipo_cuota']] * $numeroCuotas
            : $configuracion->plazo_dias;

        return DB::transaction(function () use ($actor, $garantiaIds, $primera, $datos, $configuracion, $tipo, $tipoClave, $modeloGarantia, $numeroCuotas, $plazoDias): Credito {
            $interesPersonalizado = $datos['interes'] ?? null;

            $credito = Credito::query()->create([
                'empresa_id' => $primera->empresa_id,
                'agencia_id' => $primera->agencia_id,
                'tipo_credito' => $tipoClave,
                'cliente_id' => $primera->cliente_id,
                'registrado_por' => $actor->id,
                'numero_refrendo' => 0,
                'monto_prestamo' => $datos['monto_prestamo'],
                'interes' => $interesPersonalizado ?? $configuracion->interes_default,
                'interes_solicitud_especial' => $interesPersonalizado !== null && ($datos['interes_solicitud_especial'] ?? false),
                'motivo_interes' => $interesPersonalizado !== null ? ($datos['motivo_interes'] ?? null) : null,
                'tipo_cuota' => $datos['tipo_cuota'],
                'numero_cuotas' => $numeroCuotas,
                'plazo_dias' => $plazoDias,
                'estado' => 'pendiente',
                ...$tipo->atributosExtra($datos),
            ]);

            $credito->garantiasComo($modeloGarantia)->attach($garantiaIds);
            $modeloGarantia::query()->whereIn('id', $garantiaIds)->update(['estado' => 'en_garantia']);

            // Generated here (not at aprobar()) so the admin can already
            // review the actual contrato/declaración while the crédito is
            // still pendiente, instead of deciding blind on raw fields.
            $this->documentos->generarContrato($credito, $actor);
            $this->documentos->generarDeclaracion($credito, $actor);
            $this->documentos->generarFotos($credito, $actor);

            // El sticker se pega sobre el bien/vehículo físico en tienda; un
            // hipotecario no tiene un artículo que etiquetar (la garantía es
            // el inmueble, que no pasa por tienda), así que no aplica.
            if ($tipoClave !== 'hipotecario') {
                $this->documentos->generarSticker($credito, $actor);
            }

            if ($tipoClave === 'vehicular') {
                $this->documentos->generarRecepcionVehiculos($credito, $actor);
            }

            if ($tipoClave === 'hipotecario') {
                $this->documentos->generarFichaSocioeconomica($credito, $actor);
                $this->documentos->generarNotificacionPago($credito, $actor);
                $this->documentos->generarAvisoPrejudicial($credito, $actor);
                $this->documentos->generarExpediente($credito, $actor);
            }

            $credito = $credito->fresh(['bienes']);
            $this->notificar($credito);
            $this->notificaciones->enviar($this->hierarchy->controladoresDe($credito), new CreditoSolicitadoNotification($credito));

            return $credito;
        });
    }

    public function aprobar(Credito $credito, User $aprobador): Credito
    {
        $this->asegurarEstado($credito, 'pendiente');

        return DB::transaction(function () use ($credito, $aprobador): Credito {
            $credito->update([
                'estado' => 'aprobado',
                'aprobado_por' => $aprobador->id,
                'fecha_aprobacion' => now(),
            ]);

            $credito = $credito->fresh();
            $this->notificar($credito);
            $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoAprobadoNotification($credito));

            return $credito;
        });
    }

    public function rechazar(Credito $credito, User $aprobador, string $motivo): Credito
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
    public function subsanar(Credito $credito, User $actor): Credito
    {
        $this->asegurarEstado($credito, 'rechazado');

        $modeloGarantia = $this->tipos->paraCredito($credito)->garantiaModelo();
        $garantiaIds = $this->garantiasDe($credito)->get()->pluck('id');

        if ($modeloGarantia::query()->disponibles()->whereIn('id', $garantiaIds)->count() !== $garantiaIds->count()) {
            throw new DomainException('Una o más garantías de este crédito ya están respaldando otro crédito activo.');
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
    public function revertirAprobacion(Credito $credito, User $actor): Credito
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
     * Borra un crédito registrado por error. Solo mientras está pendiente o
     * rechazado: en cuanto se aprueba/desembolsa entran en juego documentos
     * firmables, movimiento de caja y cronograma, y la vía correcta pasa a
     * ser revertirAprobacion() o la liquidación, no un borrado.
     *
     * Libera las garantías quitando el vínculo del pivot credito_garantia
     * (su estado 'en_garantia' es el normal de una garantía libre, no hay
     * nada que restaurar) y elimina los documentos del crédito junto con
     * cualquier escaneo firmado que se hubiera subido.
     */
    public function eliminar(Credito $credito): void
    {
        if (! in_array($credito->estado, ['pendiente', 'rechazado'], true)) {
            throw new DomainException('Solo se puede eliminar un crédito mientras está pendiente o rechazado.');
        }

        DB::transaction(function () use ($credito): void {
            $this->garantiasDe($credito)->detach();

            foreach ($credito->documentos as $documento) {
                if ($documento->archivo_firmado_path) {
                    Storage::disk('public')->delete($documento->archivo_firmado_path);
                }

                $documento->delete();
            }

            $credito->cuotas()->delete();

            if ($credito->conformidad_path) {
                Storage::disk('public')->delete($credito->conformidad_path);
            }

            $credito->delete();
        });
    }

    /**
     * Lets an admin override the interest rate for exceptional cases (e.g.
     * an exclusive client on a custom rate) — only before the crédito is
     * firmado, since fecha_desembolso/fecha_vencimiento and any disbursed
     * cash are already locked in past that point.
     */
    public function actualizarInteres(Credito $credito, User $actor, string $interes): Credito
    {
        if (! in_array($credito->estado, ['pendiente', 'aprobado'], true)) {
            throw new DomainException('Solo se puede editar la tasa de interés mientras el crédito está pendiente o aprobado.');
        }

        $credito->update(['interes' => $interes]);

        $credito = $credito->fresh();
        $this->notificar($credito);
        $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoInteresActualizadoNotification($credito));

        return $credito;
    }

    /**
     * Fija / corrige la fecha de desembolso de un crédito.
     *
     * - Si el crédito **aún no se desembolsó** (pendiente / aprobado): solo
     *   deja anotada la fecha; no hay cuotas ni fecha_vencimiento que tocar.
     *   Al desembolsar, si no se envía una fecha explícita, desembolsar()
     *   toma esta como fecha del cronograma (desembolso retroactivo ya
     *   planificado).
     * - Si el crédito **ya se desembolsó** (activo / vencido): desplaza
     *   fecha_vencimiento y el vencimiento de cada cuota con la misma fórmula
     *   de generarCronograma(), sin tocar los montos. En cuanto corre un
     *   refrendo/adenda/liquidación el crédito sale de ['activo','vencido']
     *   y esta edición deja de estar disponible (evita cronogramas
     *   inconsistentes).
     *
     * El nuevo vencimiento también transiciona el estado en el acto —
     * 'activo' → 'vencido' si la corrección lo deja ya vencido, o
     * 'vencido' → 'activo' si lo saca del vencimiento — para que la mora
     * (Credito::dias_en_mora, calcularMora()) quede correcta de inmediato en
     * vez de esperar al job diario creditos-prendarios:actualizar-estados.
     *
     * El movimiento de caja del desembolso NO se re-fecha (confirmado
     * explícitamente con el usuario): queda con la fecha real en que se
     * registró en el sistema, para no alterar cierres de caja ya cuadrados.
     */
    public function actualizarFechaDesembolso(Credito $credito, User $actor, string $fecha): Credito
    {
        if (! in_array($credito->estado, ['pendiente', 'aprobado', 'activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede fijar la fecha de desembolso mientras el crédito está pendiente, aprobado, activo o vencido.');
        }

        $nuevaFecha = Carbon::parse($fecha)->startOfDay();
        $diasPorCuota = self::DIAS_POR_PERIODO[$credito->tipo_cuota];
        $yaDesembolsado = $credito->cuotas()->exists();

        return DB::transaction(function () use ($credito, $nuevaFecha, $diasPorCuota, $yaDesembolsado): Credito {
            if (! $yaDesembolsado) {
                // Aún sin desembolsar: solo se anota la fecha planificada.
                $credito->update(['fecha_desembolso' => $nuevaFecha->toDateString()]);
            } else {
                $nuevaFechaVencimiento = $nuevaFecha->copy()->addDays($credito->plazo_dias);

                $credito->update([
                    'fecha_desembolso' => $nuevaFecha->toDateString(),
                    'fecha_vencimiento' => $nuevaFechaVencimiento->toDateString(),
                ]);

                foreach ($credito->cuotas as $cuota) {
                    $cuota->update([
                        'fecha_vencimiento' => $nuevaFecha->copy()->addDays($diasPorCuota * $cuota->numero_cuota)->toDateString(),
                    ]);
                }

                // El estado no es un accessor derivado como dias_en_mora: si
                // la corrección deja el vencimiento en el pasado o lo saca de
                // ahí, hay que transicionarlo ahora mismo (igual que hace
                // desembolsar() con una fecha retroactiva) en vez de esperar
                // al job diario o quedar con la mora congelada.
                $quedaVencido = $nuevaFechaVencimiento->startOfDay()->lt(now()->startOfDay());

                if ($quedaVencido && $credito->estado === 'activo') {
                    $credito = $this->transicionarAVencido($credito);
                } elseif (! $quedaVencido && $credito->estado === 'vencido') {
                    $credito->update(['estado' => 'activo']);
                }
            }

            $credito = $credito->fresh(['cuotas']);
            $this->notificar($credito);
            $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoFechaDesembolsoActualizadaNotification($credito));

            return $credito;
        });
    }

    /**
     * Corrige el número de cuotas de un crédito ya registrado (p. ej. se
     * desembolsó a 1 cuota por error y debía ser a 12).
     *
     * - Si el crédito **aún no se desembolsó** (pendiente / aprobado): solo
     *   deja anotado el nuevo número; desembolsar() lo toma como override si
     *   no recibe uno explícito propio.
     * - Si el crédito **ya se desembolsó** (activo / vencido): se exige que
     *   todavía no tenga cobros registrados — el cronograma se borra y se
     *   regenera desde cero con el nuevo número de cuotas (misma fórmula de
     *   generarCronograma()), y plazo_dias/fecha_vencimiento se recalculan a
     *   partir de la fecha_desembolso ya fijada. Igual que
     *   actualizarFechaDesembolso(), transiciona el estado en el acto si el
     *   nuevo vencimiento lo deja vencido o lo saca del vencimiento.
     */
    public function actualizarNumeroCuotas(Credito $credito, User $actor, int $numeroCuotas): Credito
    {
        if (! in_array($credito->estado, ['pendiente', 'aprobado', 'activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede corregir el número de cuotas mientras el crédito está pendiente, aprobado, activo o vencido.');
        }

        $diasPorCuota = self::DIAS_POR_PERIODO[$credito->tipo_cuota];
        $yaDesembolsado = $credito->cuotas()->exists();

        if ($yaDesembolsado && $credito->cobros()->exists()) {
            throw new DomainException('No se puede corregir el número de cuotas: el crédito ya tiene cobros registrados.');
        }

        return DB::transaction(function () use ($credito, $numeroCuotas, $diasPorCuota, $yaDesembolsado): Credito {
            if (! $yaDesembolsado) {
                $credito->update(['numero_cuotas' => $numeroCuotas]);
            } else {
                $nuevoPlazoDias = $diasPorCuota * $numeroCuotas;
                $nuevaFechaVencimiento = $credito->fecha_desembolso->copy()->addDays($nuevoPlazoDias);

                $credito->cuotas()->delete();

                $credito->update([
                    'numero_cuotas' => $numeroCuotas,
                    'plazo_dias' => $nuevoPlazoDias,
                    'fecha_vencimiento' => $nuevaFechaVencimiento->toDateString(),
                ]);

                $this->generarCronograma($credito, $numeroCuotas, $diasPorCuota);

                $quedaVencido = $nuevaFechaVencimiento->copy()->startOfDay()->lt(now()->startOfDay());

                if ($quedaVencido && $credito->estado === 'activo') {
                    $credito = $this->transicionarAVencido($credito);
                } elseif (! $quedaVencido && $credito->estado === 'vencido') {
                    $credito->update(['estado' => 'activo']);
                }
            }

            $credito = $credito->fresh(['cuotas']);
            $this->notificar($credito);
            $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoNumeroCuotasActualizadoNotification($credito));

            return $credito;
        });
    }

    /**
     * Activates the crédito, moves the cash out of the actor's own caja, and
     * generates the cuotas cronograma. Replaces the old firmar() confirm-
     * button — signing is now proven by each documento's uploaded scan
     * (DocumentoCreditoService::subirFirmado()), so this only needs
     * to check that every documento already has a firmado_at.
     *
     * plazo_dias/fecha_vencimiento are computed HERE (not from the config
     * value snapshotted at registrar() time) as número de cuotas × the
     * tipo_cuota's fixed period length — confirmed explicitly: choosing
     * more cuotas than the default extends the crédito's real term (e.g. 2
     * cuotas mensuales = 60 días reales, not 2 checkpoints inside the same
     * 30 días).
     *
     * If this crédito came from adendar() (adenda_de_credito_id set), no
     * cash moves at all — the client isn't receiving new money, this is
     * only restructuring the terms of a debt that was already desembolsado
     * under the original crédito. Confirmed explicitly with the user.
     *
     * $fecha (opcional, solo admin) permite un desembolso retroactivo:
     * regularizar en umax un préstamo cuyo dinero se entregó en el pasado.
     * Solo desplaza el cronograma (fecha_desembolso, fecha_vencimiento y
     * cada cuota se calculan desde esa fecha); el movimiento de caja se
     * fecha igual con el día real del ciclo, no con la fecha retroactiva,
     * para no descuadrar cierres de caja. Si con la fecha retroactiva el
     * crédito ya nace vencido, se transiciona a 'vencido' en el acto en
     * lugar de esperar al job diario.
     */
    public function desembolsar(Credito $credito, User $actor, ?int $numeroCuotas, ?string $interes, ?string $fecha = null): Credito
    {
        $this->asegurarEstado($credito, 'aprobado');

        // Los escaneos firmados de contrato/declaración se suben DESPUÉS del
        // desembolso (confirmado con el usuario): aquí ya no se exige que
        // todos los documentos tengan firmado_at.
        $esAdenda = $credito->adenda_de_credito_id !== null;
        $ciclo = null;

        if (! $esAdenda) {
            $caja = Caja::query()->where('user_id', $actor->id)->first();
            $ciclo = $caja?->cicloAbierto()->first();

            if (! $ciclo) {
                throw new DomainException('Debes aperturar tu caja antes de desembolsar.');
            }

            if (bccomp($credito->monto_prestamo, $ciclo->saldoActual(), 2) > 0) {
                throw new DomainException('No tienes saldo suficiente en tu caja para desembolsar este crédito.');
            }
        }

        return DB::transaction(function () use ($credito, $actor, $ciclo, $esAdenda, $numeroCuotas, $interes, $fecha): Credito {
            if ($interes !== null) {
                $credito->update(['interes' => $interes]);
            }

            $n = $numeroCuotas ?? $credito->numero_cuotas ?? self::CUOTAS_POR_TIPO[$credito->tipo_cuota];
            $diasPorCuota = self::DIAS_POR_PERIODO[$credito->tipo_cuota];
            $plazoTotal = $diasPorCuota * $n;

            // Prioridad: fecha explícita del formulario → fecha planificada ya
            // anotada en el crédito (actualizarFechaDesembolso en pendiente) → hoy.
            $fechaDesembolso = $fecha !== null
                ? Carbon::parse($fecha)->startOfDay()
                : ($credito->fecha_desembolso?->copy()->startOfDay() ?? now()->startOfDay());

            $credito->update([
                'estado' => 'activo',
                'fecha_desembolso' => $fechaDesembolso->toDateString(),
                'plazo_dias' => $plazoTotal,
                'fecha_vencimiento' => $fechaDesembolso->copy()->addDays($plazoTotal)->toDateString(),
            ]);

            if (! $esAdenda) {
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
            }

            $credito = $credito->fresh();
            $this->generarCronograma($credito, $n, $diasPorCuota);

            $this->documentos->generarVoucherDesembolso($credito, $actor, [
                'monto' => (string) $credito->monto_prestamo,
                'fecha_desembolso' => $credito->fecha_desembolso->toDateString(),
                'fecha_vencimiento' => $credito->fecha_vencimiento->toDateString(),
                'plazo_dias' => $credito->plazo_dias,
                'numero_cuotas' => $n,
                'tipo_cuota' => $credito->tipo_cuota,
                'interes' => (string) $credito->interes,
                'medio' => $esAdenda ? 'sin_movimiento_caja' : 'efectivo',
                'saldo_caja' => $esAdenda ? null : $ciclo->fresh()->saldoActual(),
            ]);

            $this->notificar($credito);
            $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoDesembolsadoNotification($credito));

            if ($credito->fecha_vencimiento->copy()->startOfDay()->lt(now()->startOfDay())) {
                $credito = $this->transicionarAVencido($credito);
            }

            return $credito;
        });
    }

    /**
     * Amortiza el capital en partes iguales entre las n cuotas (la última
     * absorbe el residuo de redondeo, para que la suma cuadre exacto con
     * monto_prestamo). El interés de cada cuota se calcula sobre el
     * monto_prestamo ORIGINAL completo, no sobre saldo insoluto — mismo
     * monto de interés en cada cuota independientemente de cuánto capital
     * ya amortizaron las cuotas anteriores (confirmado explícitamente con
     * el usuario). Mismo formato de tasa mensual prorateada que
     * calcularMontoLiquidacion(), evaluado a un período fijo en vez de días
     * transcurridos.
     */
    private function generarCronograma(Credito $credito, int $n, int $diasPorCuota): void
    {
        foreach ($this->filasCronograma((string) $credito->monto_prestamo, (string) $credito->interes, $n, $diasPorCuota) as $fila) {
            CuotaCredito::query()->create([
                'credito_id' => $credito->id,
                'empresa_id' => $credito->empresa_id,
                'numero_cuota' => $fila['numero_cuota'],
                'fecha_vencimiento' => $credito->fecha_desembolso->copy()->addDays($fila['dias']),
                'monto_capital' => $fila['monto_capital'],
                'monto_interes' => $fila['monto_interes'],
                'monto_total' => $fila['monto_total'],
            ]);
        }
    }

    /**
     * Filas del cronograma (sin persistir): capital amortizado en partes
     * iguales con la última cuota absorbiendo el redondeo, interés fijo por
     * cuota sobre el monto original completo. `dias` es el desfase desde el
     * desembolso hasta el vencimiento de esa cuota.
     *
     * @return list<array{numero_cuota: int, dias: int, monto_capital: string, monto_interes: string, monto_total: string}>
     */
    private function filasCronograma(string $monto, string $interes, int $n, int $diasPorCuota): array
    {
        $capitalPorCuota = bcdiv($monto, (string) $n, 2);
        $saldoCapital = $monto;

        $factor = bcmul($monto, $interes, 10);
        $interesCuota = bcdiv(bcmul($factor, (string) $diasPorCuota, 10), '3000', 2);

        $filas = [];

        for ($i = 1; $i <= $n; $i++) {
            $capitalCuota = $i === $n ? $saldoCapital : $capitalPorCuota;

            $filas[] = [
                'numero_cuota' => $i,
                'dias' => $diasPorCuota * $i,
                'monto_capital' => $capitalCuota,
                'monto_interes' => $interesCuota,
                'monto_total' => bcadd($capitalCuota, $interesCuota, 2),
            ];

            $saldoCapital = bcsub($saldoCapital, $capitalCuota, 2);
        }

        return $filas;
    }

    /**
     * Cronograma tentativo para mostrar al registrar el crédito, cuando aún
     * no hay cuotas persistidas: usa la fecha de hoy como desembolso y el
     * número de cuotas por defecto del tipo (misma fórmula que
     * generarCronograma()). No toca la base de datos.
     *
     * @return array{fecha_base: string, plazo_dias: int, cuotas: list<array{numero_cuota: int, fecha_vencimiento: string, monto_capital: string, monto_interes: string, monto_total: string}>}
     */
    public function previsualizarCronograma(string $monto, string $interes, string $tipoCuota, ?int $numeroCuotas = null): array
    {
        if (! isset(self::CUOTAS_POR_TIPO[$tipoCuota])) {
            throw new DomainException("Tipo de cuota inválido: {$tipoCuota}");
        }

        $n = $numeroCuotas ?? self::CUOTAS_POR_TIPO[$tipoCuota];
        $diasPorCuota = self::DIAS_POR_PERIODO[$tipoCuota];
        $base = now()->startOfDay();

        $cuotas = array_map(fn (array $fila): array => [
            'numero_cuota' => $fila['numero_cuota'],
            'fecha_vencimiento' => $base->copy()->addDays($fila['dias'])->toDateString(),
            'monto_capital' => $fila['monto_capital'],
            'monto_interes' => $fila['monto_interes'],
            'monto_total' => $fila['monto_total'],
        ], $this->filasCronograma($monto, $interes, $n, $diasPorCuota));

        return [
            'fecha_base' => $base->toDateString(),
            'plazo_dias' => $diasPorCuota * $n,
            'cuotas' => $cuotas,
        ];
    }

    /**
     * Cierra el crédito actual y genera un sucesor encadenado — cubre tanto
     * el "Refrendar" puro (paga exactamente el interés, el capital pasa
     * intacto) como un abono a capital (paga interés + una parte del
     * capital, confirmado con el usuario vía un ejemplo: crédito 1000 +
     * interés 200, paga 300 -> sucesor con capital 900). Ambos son la misma
     * operación: el excedente sobre el interés siempre abona a capital, que
     * es cero en el caso de un refrendo puro. Pagar el total completo no
     * está permitido aquí — eso es Liquidar.
     */
    public function refrendar(
        Credito $credito,
        User $actor,
        string $montoPagado,
        string $medio,
        ?UploadedFile $comprobante,
        ?string $descuento = null,
        ?string $motivoDescuento = null,
    ): Credito {
        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede refrendar un crédito activo o vencido.');
        }

        $calculo = $this->calcularMontoRefrendo($credito);
        $interes = $calculo['interes'];
        $mora = $calculo['mora'];
        $descuento = $this->resolverDescuento($calculo['total'], $descuento, $motivoDescuento);
        $interesConMora = bcsub($calculo['total'], $descuento, 2);
        $total = bcadd((string) $credito->monto_prestamo, $interesConMora, 2);

        if (bccomp($montoPagado, $interesConMora, 2) < 0) {
            throw new DomainException("El monto pagado ({$montoPagado}) es menor al interés + mora a refrendar calculado ({$interesConMora}).");
        }

        if (bccomp($montoPagado, $total, 2) >= 0) {
            throw new DomainException("El monto pagado ({$montoPagado}) cubre el total del crédito ({$total}); selecciona Liquidar para cancelarlo.");
        }

        $abonoCapital = bcsub($montoPagado, $interesConMora, 2);
        $nuevoCapital = bcsub((string) $credito->monto_prestamo, $abonoCapital, 2);

        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);
        $siguienteNumero = $credito->numero_refrendo + 1;

        if ($configuracion->max_refrendos !== null && $siguienteNumero > $configuracion->max_refrendos) {
            throw new DomainException("Este crédito ya alcanzó el máximo de {$configuracion->max_refrendos} refrendos permitidos; debe liquidarse el capital.");
        }

        $modeloGarantia = $this->tipos->paraCredito($credito)->garantiaModelo();
        $ciclo = $this->resolverCicloParaCobro($actor);

        return DB::transaction(function () use ($credito, $actor, $siguienteNumero, $nuevoCapital, $interes, $mora, $descuento, $motivoDescuento, $abonoCapital, $ciclo, $montoPagado, $medio, $comprobante, $modeloGarantia): Credito {
            $credito->update(['estado' => 'refrendado']);

            $n = self::CUOTAS_POR_TIPO[$credito->tipo_cuota];
            $diasPorCuota = self::DIAS_POR_PERIODO[$credito->tipo_cuota];
            $plazoTotal = $diasPorCuota * $n;

            $fechaDesembolso = now()->startOfDay();

            $nuevo = Credito::query()->create([
                'empresa_id' => $credito->empresa_id,
                'agencia_id' => $credito->agencia_id,
                'tipo_credito' => $credito->tipo_credito,
                'cliente_id' => $credito->cliente_id,
                'supervisado_por' => $credito->supervisado_por,
                'registrado_por' => $actor->id,
                'refrendo_de_credito_id' => $credito->id,
                'numero_refrendo' => $siguienteNumero,
                'monto_prestamo' => $nuevoCapital,
                'interes' => $credito->interes,
                'tipo_cuota' => $credito->tipo_cuota,
                'plazo_dias' => $plazoTotal,
                'estado' => 'activo',
                'fecha_desembolso' => $fechaDesembolso->toDateString(),
                'fecha_vencimiento' => $fechaDesembolso->copy()->addDays($plazoTotal)->toDateString(),
            ]);

            $nuevo->garantiasComo($modeloGarantia)->attach($this->garantiasDe($credito)->get()->pluck('id'));

            // Un refrendo puro no cambia ninguna condición del crédito (misma
            // tasa, mismo tipo de cuota), así que conserva los mismos
            // documentos que un registro nuevo — "adenda" es un documento
            // distinto, reservado para cuando SÍ se modifican las condiciones.
            $this->documentos->generarContrato($nuevo, $actor);
            $this->documentos->generarDeclaracion($nuevo, $actor);
            $this->documentos->generarFotos($nuevo, $actor);

            if ($credito->tipo_credito === 'hipotecario') {
                $this->documentos->generarFichaSocioeconomica($nuevo, $actor);
                $this->documentos->generarNotificacionPago($nuevo, $actor);
                $this->documentos->generarAvisoPrejudicial($nuevo, $actor);
                $this->documentos->generarExpediente($nuevo, $actor);
            } else {
                $this->documentos->generarSticker($nuevo, $actor);
            }

            $nuevo = $nuevo->fresh(['bienes']);
            $this->generarCronograma($nuevo, $n, $diasPorCuota);
            $this->registrarCobroEnCaja($ciclo, $actor, $credito, $montoPagado, $medio, $comprobante, "Refrendo de crédito prendario #{$credito->id}", [
                'operacion' => 'refrendo',
                'interes' => $interes,
                'mora' => $mora,
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'credito_sucesor_id' => $nuevo->id,
            ]);

            $this->documentos->generarVoucherPago($credito, $actor, [
                'operacion' => 'refrendo',
                'monto_pagado' => $montoPagado,
                'medio' => $medio,
                'interes' => $interes,
                'mora' => $mora,
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'abono_capital' => $abonoCapital,
                'saldo_capital' => $nuevoCapital,
                'vuelto' => '0.00',
                'credito_id' => $credito->id,
                'credito_sucesor_id' => $nuevo->id,
                'fecha' => now()->toDateString(),
            ]);

            $this->notificar($nuevo);
            $this->notificaciones->enviar(collect([$nuevo->registradoPor]), new CreditoRefrendadoNotification($nuevo));

            return $nuevo;
        });
    }

    /**
     * Un refrendo QUE puede modificar condiciones (tasa de interés y,
     * opcionalmente, tipo de cuota) — a diferencia de refrendar(), que
     * reactiva el sucesor de inmediato con las mismas condiciones, aquí el
     * sucesor SIEMPRE nace en pendiente y debe volver a pasar por
     * aprobar/firmar/desembolsar, con contrato/declaración regenerados.
     * $nuevoInteres/$nuevoTipoCuota son opcionales a propósito: un asesor
     * (autorizado igual que refrendar(), ver Policy::adendar()) normalmente
     * solo cobra el interés y deja el sucesor con la tasa/cuota actuales; un
     * admin puede fijarlas aquí mismo o editarlas después mientras está
     * pendiente/aprobado (CreditoController::editar()/actualizarInteres()).
     * El monto_prestamo se conserva igual que en el crédito original (mismo
     * cálculo de abono a capital que refrendar() si pagan de más);
     * desembolsar() detecta adenda_de_credito_id y NO mueve caja — no se
     * entrega dinero nuevo, solo se reescriben las condiciones de una deuda
     * que ya estaba desembolsada.
     */
    public function adendar(
        Credito $credito,
        User $actor,
        string $montoPagado,
        ?string $nuevoInteres,
        ?string $nuevoTipoCuota,
        string $medio,
        ?UploadedFile $comprobante,
        ?string $descuento = null,
        ?string $motivoDescuento = null,
    ): Credito {
        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede hacer una adenda a un crédito activo o vencido.');
        }

        $calculo = $this->calcularMontoRefrendo($credito);
        $interes = $calculo['interes'];
        $mora = $calculo['mora'];
        $descuento = $this->resolverDescuento($calculo['total'], $descuento, $motivoDescuento);
        $interesConMora = bcsub($calculo['total'], $descuento, 2);
        $total = bcadd((string) $credito->monto_prestamo, $interesConMora, 2);

        if (bccomp($montoPagado, $interesConMora, 2) < 0) {
            throw new DomainException("El monto pagado ({$montoPagado}) es menor al interés + mora calculado ({$interesConMora}).");
        }

        if (bccomp($montoPagado, $total, 2) >= 0) {
            throw new DomainException("El monto pagado ({$montoPagado}) cubre el total del crédito ({$total}); selecciona Liquidar para cancelarlo.");
        }

        $abonoCapital = bcsub($montoPagado, $interesConMora, 2);
        $nuevoCapital = bcsub((string) $credito->monto_prestamo, $abonoCapital, 2);
        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);
        $modeloGarantia = $this->tipos->paraCredito($credito)->garantiaModelo();
        $ciclo = $this->resolverCicloParaCobro($actor);

        return DB::transaction(function () use ($credito, $actor, $nuevoInteres, $nuevoTipoCuota, $nuevoCapital, $interes, $mora, $descuento, $motivoDescuento, $abonoCapital, $configuracion, $ciclo, $montoPagado, $medio, $comprobante, $modeloGarantia): Credito {
            $credito->update(['estado' => 'adendado']);

            $nuevo = Credito::query()->create([
                'empresa_id' => $credito->empresa_id,
                'agencia_id' => $credito->agencia_id,
                'tipo_credito' => $credito->tipo_credito,
                'cliente_id' => $credito->cliente_id,
                'supervisado_por' => $credito->supervisado_por,
                // El asesor dueño del caso original, NO $actor — cuando un
                // admin es quien ejecuta la adenda (p.ej. para dejar ya la
                // tasa nueva), si el sucesor quedara a su nombre, el asesor
                // (con visibleQuery scopeado a registrado_por=propio)
                // perdería visibilidad de su propio crédito justo cuando
                // necesita firmar los documentos nuevos.
                'registrado_por' => $credito->registrado_por,
                'adenda_de_credito_id' => $credito->id,
                'numero_refrendo' => 0,
                'monto_prestamo' => $nuevoCapital,
                // Un asesor normalmente omite esto (solo cobra el interés);
                // el sucesor conserva la tasa/tipo de cuota actuales y un
                // admin las edita después, ya con el crédito pendiente.
                'interes' => $nuevoInteres ?? $credito->interes,
                'tipo_cuota' => $nuevoTipoCuota ?? $credito->tipo_cuota,
                'plazo_dias' => $configuracion->plazo_dias,
                'estado' => 'pendiente',
            ]);

            $nuevo->garantiasComo($modeloGarantia)->attach($this->garantiasDe($credito)->get()->pluck('id'));

            $this->documentos->generarContrato($nuevo, $actor);
            $this->documentos->generarDeclaracion($nuevo, $actor);
            $this->documentos->generarFotos($nuevo, $actor);

            if ($credito->tipo_credito === 'hipotecario') {
                $this->documentos->generarFichaSocioeconomica($nuevo, $actor);
                $this->documentos->generarNotificacionPago($nuevo, $actor);
                $this->documentos->generarAvisoPrejudicial($nuevo, $actor);
                $this->documentos->generarExpediente($nuevo, $actor);
            } else {
                $this->documentos->generarSticker($nuevo, $actor);
            }

            $nuevo = $nuevo->fresh(['bienes']);
            $this->registrarCobroEnCaja($ciclo, $actor, $credito, $montoPagado, $medio, $comprobante, "Adenda de crédito prendario #{$credito->id}", [
                'operacion' => 'adenda',
                'interes' => $interes,
                'mora' => $mora,
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'credito_sucesor_id' => $nuevo->id,
            ]);

            $this->documentos->generarVoucherPago($credito, $actor, [
                'operacion' => 'adenda',
                'monto_pagado' => $montoPagado,
                'medio' => $medio,
                'interes' => $interes,
                'mora' => $mora,
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'abono_capital' => $abonoCapital,
                'saldo_capital' => $nuevoCapital,
                'vuelto' => '0.00',
                'nuevo_interes' => $nuevoInteres ?? (string) $credito->interes,
                'nuevo_tipo_cuota' => $nuevoTipoCuota ?? $credito->tipo_cuota,
                'credito_id' => $credito->id,
                'credito_sucesor_id' => $nuevo->id,
                'fecha' => now()->toDateString(),
            ]);

            $this->notificar($nuevo);
            $this->notificaciones->enviar(collect([$nuevo->registradoPor]), new CreditoAdendadoNotification($nuevo));

            return $nuevo;
        });
    }

    /**
     * Refinancia un crédito hipotecario: el capital del sucesor arranca en
     * capital + interés + mora del actual (todo lo que se debe), no solo el
     * interés como en refrendar/adendar — confirmado explícitamente: puede
     * refinanciarse el 100% de la deuda (montoPagado = 0, todo se traslada)
     * o pagar una parte ahora y refinanciar solo la diferencia. El sucesor
     * nace "pendiente" (como adendar) porque cambia sustancialmente la
     * estructura de la deuda y amerita una nueva revisión/firma.
     */
    public function refinanciar(
        Credito $credito,
        User $actor,
        ?string $montoPagado,
        string $medio,
        ?UploadedFile $comprobante,
        ?string $descuento = null,
        ?string $motivoDescuento = null,
    ): Credito {
        if ($credito->tipo_credito !== 'hipotecario') {
            throw new DomainException('Solo los créditos hipotecarios pueden refinanciarse.');
        }

        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede refinanciar un crédito activo o vencido.');
        }

        $montoPagado = $montoPagado !== null && $montoPagado !== '' ? $montoPagado : '0.00';

        $liquidacion = $this->calcularMontoLiquidacion($credito);
        $descuento = $this->resolverDescuento($liquidacion['total'], $descuento, $motivoDescuento);
        $deudaTotal = bcsub($liquidacion['total'], $descuento, 2);

        if (bccomp($montoPagado, $deudaTotal, 2) >= 0) {
            throw new DomainException("El monto pagado ({$montoPagado}) cubre el total de la deuda ({$deudaTotal}); selecciona Liquidar para cancelarlo.");
        }

        $nuevoCapital = bcsub($deudaTotal, $montoPagado, 2);
        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);
        $modeloGarantia = $this->tipos->paraCredito($credito)->garantiaModelo();

        return DB::transaction(function () use ($credito, $actor, $nuevoCapital, $liquidacion, $descuento, $motivoDescuento, $deudaTotal, $configuracion, $montoPagado, $medio, $comprobante, $modeloGarantia): Credito {
            $credito->update(['estado' => 'refinanciado']);

            $nuevo = Credito::query()->create([
                'empresa_id' => $credito->empresa_id,
                'agencia_id' => $credito->agencia_id,
                'tipo_credito' => $credito->tipo_credito,
                'cliente_id' => $credito->cliente_id,
                'supervisado_por' => $credito->supervisado_por,
                'registrado_por' => $credito->registrado_por,
                'refinanciamiento_de_credito_id' => $credito->id,
                'monto_prestamo' => $nuevoCapital,
                'interes' => $credito->interes,
                'tipo_cuota' => $credito->tipo_cuota,
                'plazo_dias' => $configuracion->plazo_dias,
                'estado' => 'pendiente',
            ]);

            $nuevo->garantiasComo($modeloGarantia)->attach($this->garantiasDe($credito)->get()->pluck('id'));

            $this->documentos->generarContrato($nuevo, $actor);
            $this->documentos->generarDeclaracion($nuevo, $actor);
            $this->documentos->generarFotos($nuevo, $actor);
            $this->documentos->generarFichaSocioeconomica($nuevo, $actor);
            $this->documentos->generarNotificacionPago($nuevo, $actor);
            $this->documentos->generarAvisoPrejudicial($nuevo, $actor);
            $this->documentos->generarExpediente($nuevo, $actor);

            $nuevo = $nuevo->fresh(['inmuebles']);
            $this->registrarCobroRefinanciamiento($actor, $credito, $montoPagado, $medio, $comprobante, [
                'interes' => $liquidacion['interes'],
                'mora' => $liquidacion['mora'],
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'credito_sucesor_id' => $nuevo->id,
            ]);

            $this->documentos->generarVoucherPago($credito, $actor, [
                'operacion' => 'refinanciamiento',
                'monto_pagado' => $montoPagado,
                'medio' => $medio,
                'capital' => $liquidacion['capital'],
                'interes' => $liquidacion['interes'],
                'mora' => $liquidacion['mora'],
                'dias_mora' => $liquidacion['dias_mora'],
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'deuda_total' => $deudaTotal,
                'nuevo_capital' => $nuevoCapital,
                'credito_id' => $credito->id,
                'credito_sucesor_id' => $nuevo->id,
                'fecha' => now()->toDateString(),
            ]);

            $this->notificar($nuevo);
            $this->notificaciones->enviar(collect([$nuevo->registradoPor]), new CreditoRefinanciadoNotification($nuevo));

            return $nuevo;
        });
    }

    public function liquidar(
        Credito $credito,
        User $actor,
        string $montoPagado,
        string $medio,
        ?UploadedFile $comprobante,
        ?string $descuento = null,
        ?string $motivoDescuento = null,
    ): Credito {
        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede liquidar un crédito activo o vencido.');
        }

        $liquidacion = $this->calcularMontoLiquidacion($credito);
        $descuento = $this->resolverDescuento($liquidacion['total'], $descuento, $motivoDescuento);
        $montoCalculado = bcsub($liquidacion['total'], $descuento, 2);

        if (bccomp($montoPagado, $montoCalculado, 2) < 0) {
            throw new DomainException("El monto pagado ({$montoPagado}) es menor al monto a liquidar calculado ({$montoCalculado}).");
        }

        $ciclo = $this->resolverCicloParaCobro($actor);

        return DB::transaction(function () use ($credito, $actor, $ciclo, $montoPagado, $medio, $comprobante, $liquidacion, $descuento, $motivoDescuento, $montoCalculado): Credito {
            $credito->update(['estado' => 'liquidado_pendiente']);

            $credito = $credito->fresh(['bienes']);
            $this->registrarCobroEnCaja($ciclo, $actor, $credito, $montoPagado, $medio, $comprobante, "Liquidación de crédito prendario #{$credito->id}", [
                'operacion' => 'liquidacion',
                'interes' => $liquidacion['interes'],
                'mora' => $liquidacion['mora'],
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'vuelto' => bcsub($montoPagado, $montoCalculado, 2),
            ]);
            $this->documentos->generarDevolucion($credito, $actor);

            $this->documentos->generarVoucherPago($credito, $actor, [
                'operacion' => 'liquidacion',
                'monto_pagado' => $montoPagado,
                'medio' => $medio,
                'capital' => $liquidacion['capital'],
                'interes' => $liquidacion['interes'],
                'mora' => $liquidacion['mora'],
                'dias_mora' => $liquidacion['dias_mora'],
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'total' => $montoCalculado,
                'vuelto' => bcsub($montoPagado, $montoCalculado, 2),
                'credito_id' => $credito->id,
                'fecha' => now()->toDateString(),
            ]);

            $this->notificar($credito);

            return $credito->fresh(['bienes', 'documentos']);
        });
    }

    /**
     * El pago ya se cobró en liquidar() — lo que falta para que el crédito
     * quede realmente liquidado es la firma del acta de devolución, que
     * confirma que los bienes fueron físicamente entregados de vuelta al
     * cliente. Hasta entonces el crédito queda "liquidado_pendiente" y sus
     * bienes siguen indisponibles (ver Bien::scopeDisponibles()). Se llama
     * desde el mismo flujo genérico de "subir documento firmado" — un no-op
     * si el documento subido no es la devolución o el crédito ya no está
     * pendiente de ella.
     */
    public function confirmarLiquidacionSiCorresponde(Credito $credito, DocumentoCredito $documento, User $actor): void
    {
        if ($documento->tipo !== 'devolucion' || $credito->estado !== 'liquidado_pendiente') {
            return;
        }

        DB::transaction(function () use ($credito, $actor): void {
            $credito->update(['estado' => 'liquidado']);

            $modeloGarantia = $this->tipos->paraCredito($credito)->garantiaModelo();
            $modeloGarantia::query()->whereIn('id', $this->garantiasDe($credito)->get()->pluck('id'))->update(['estado' => 'recuperado']);

            $this->documentos->generarCartaNoAdeudo($credito, $actor);

            $credito = $credito->fresh(['bienes', 'registradoPor']);
            $this->notificar($credito);
            $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoLiquidadoNotification($credito));
        });
    }

    /**
     * Interest owed today: prorated by days elapsed since fecha_desembolso,
     * with a configurable minimum floor (so closing out early still charges
     * at least N days of interest) — confirmed with the user via a worked
     * example: monto_prestamo=1000, interés=20% mensual, mínimo=15 días;
     * canceling at day 5 still charges as if 15 days passed, canceling at
     * day 17 charges for the actual 17. Shared by calcularMontoLiquidacion()
     * (capital + this interest) and calcularMontoRefrendo() (this interest
     * alone — refrendar keeps the capital outstanding).
     *
     * @return array{interes: string, dias_transcurridos: int, dias_minimo: int, dias_cobrados: int, tasa_interes: string}
     */
    private function calcularInteresProrateado(Credito $credito): array
    {
        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);

        $diasTranscurridos = max(0, (int) $credito->fecha_desembolso->copy()->startOfDay()->diffInDays(now()->startOfDay()));
        $diasCobrables = max($diasTranscurridos, $configuracion->dias_minimo_interes);

        // Un solo bcdiv al final (no una tasa diaria redondeada intermedia)
        // para no arrastrar error de truncamiento en cada paso.
        $factor = bcmul((string) $credito->monto_prestamo, (string) $credito->interes, 10);
        $factor = bcmul($factor, (string) $diasCobrables, 10);
        $interes = bcdiv($factor, '3000', 2); // /100 (%) /30 (días del mes)

        return [
            'interes' => $interes,
            'dias_transcurridos' => $diasTranscurridos,
            'dias_minimo' => $configuracion->dias_minimo_interes,
            'dias_cobrados' => $diasCobrables,
            'tasa_interes' => (string) $credito->interes,
        ];
    }

    /**
     * @return array{capital: string, interes: string, mora: string, dias_mora: int, total: string, dias_transcurridos: int, dias_minimo: int, dias_cobrados: int, tasa_interes: string}
     */
    public function calcularMontoLiquidacion(Credito $credito): array
    {
        $prorateo = $this->calcularInteresProrateado($credito);
        $mora = $this->calcularMora($credito);
        $total = bcadd(bcadd((string) $credito->monto_prestamo, $prorateo['interes'], 2), $mora, 2);

        return [
            'capital' => (string) $credito->monto_prestamo,
            'interes' => $prorateo['interes'],
            'mora' => $mora,
            'dias_mora' => $credito->dias_en_mora,
            'total' => $total,
            'dias_transcurridos' => $prorateo['dias_transcurridos'],
            'dias_minimo' => $prorateo['dias_minimo'],
            'dias_cobrados' => $prorateo['dias_cobrados'],
            'tasa_interes' => $prorateo['tasa_interes'],
        ];
    }

    /**
     * Monto mínimo a pagar al refrendar/adendar: interés prorateado + mora
     * (el capital sigue de pie, a diferencia de liquidar). Antes de agregar
     * la mora aquí, un crédito vencido se podía refrendar/adendar pagando
     * solo el interés, sin penalidad por el atraso — confirmado explícitamente
     * que la mora debe cobrarse también en estos dos flujos, no solo al liquidar.
     *
     * @return array{interes: string, mora: string, total: string, dias_transcurridos: int, dias_minimo: int, dias_cobrados: int, tasa_interes: string}
     */
    public function calcularMontoRefrendo(Credito $credito): array
    {
        $prorateo = $this->calcularInteresProrateado($credito);
        $mora = $this->calcularMora($credito);

        return [
            'interes' => $prorateo['interes'],
            'mora' => $mora,
            'total' => bcadd($prorateo['interes'], $mora, 2),
            'dias_transcurridos' => $prorateo['dias_transcurridos'],
            'dias_minimo' => $prorateo['dias_minimo'],
            'dias_cobrados' => $prorateo['dias_cobrados'],
            'tasa_interes' => $prorateo['tasa_interes'],
        ];
    }

    /**
     * Resuelve el descuento a aplicar sobre `$montoBase` (interés+mora en
     * refrendo/adenda, o el total completo en liquidación) — nunca puede
     * exceder esa base (no tiene sentido "descontar" más de lo que se debe
     * por ese concepto) y exige un motivo en cuanto es mayor a cero, para
     * dejar auditoría de por qué se condonó dinero.
     *
     * @return string el descuento normalizado (nunca null)
     */
    private function resolverDescuento(string $montoBase, ?string $descuento, ?string $motivoDescuento): string
    {
        if ($descuento === null || bccomp($descuento, '0', 2) <= 0) {
            return '0.00';
        }

        if (blank($motivoDescuento)) {
            throw new DomainException('Debes indicar el motivo del descuento.');
        }

        if (bccomp($descuento, $montoBase, 2) > 0) {
            throw new DomainException("El descuento ({$descuento}) no puede superar el monto a cobrar ({$montoBase}).");
        }

        return $descuento;
    }

    /**
     * Daily state transitions: activo -> vencido once fecha_vencimiento passes,
     * vencido -> en_venta once the configured días de espera also pass. Each
     * transitioned crédito is broadcast/notificado individually (same as
     * every user-triggered transition) so an open module reflects it live
     * instead of only on next reload.
     */
    public function actualizarEstadosVencidos(): void
    {
        $hoy = now()->startOfDay()->toDateString();

        Credito::query()
            ->where('estado', 'activo')
            ->whereDate('fecha_vencimiento', '<', $hoy)
            ->get()
            ->each(fn (Credito $credito) => $this->transicionarAVencido($credito));

        Credito::query()
            ->where('estado', 'vencido')
            ->with(['agencia'])
            ->get()
            ->each(function (Credito $credito) use ($hoy): void {
                if ($this->fechaLimiteEspera($credito) >= $hoy) {
                    return;
                }

                // Tipos con conformidad (vehicular / hipotecario) no pasan
                // directo a la tienda: esperan la conformidad del notario/
                // abogado (ver confirmarConformidad()).
                if ($this->tipos->paraCredito($credito)->requiereConformidadPreviaATienda() && $credito->conformidad_confirmada_at === null) {
                    $this->transicionarAPendienteConformidad($credito);

                    return;
                }

                $this->transicionarAEnVenta($credito);
            });
    }

    /**
     * Manual counterpart to the daily vencido -> en_venta transition
     * actualizarEstadosVencidos() does in batch — lets an admin send a
     * specific crédito to the tienda as soon as it's past the período de
     * espera, without waiting for the next scheduled run. The admin sets the
     * sale price per bien here; the batch path falls back to the valorización.
     *
     * @param  array<int, numeric-string|float|int>  $preciosPorBien  keyed by garantía id
     */
    public function enviarATienda(Credito $credito, User $actor, array $preciosPorBien = []): Credito
    {
        $tipo = $this->tipos->paraCredito($credito);
        $requiereConformidad = $tipo->requiereConformidadPreviaATienda();

        if ($credito->estado === 'vencido') {
            $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);

            if ($this->fechaLimiteEspera($credito) >= now()->startOfDay()->toDateString()) {
                throw new DomainException("Este crédito aún no supera los {$configuracion->dias_espera_mora} días de espera configurados.");
            }

            if ($requiereConformidad && $credito->conformidad_confirmada_at === null) {
                return DB::transaction(fn (): Credito => $this->transicionarAPendienteConformidad($credito));
            }

            return DB::transaction(fn (): Credito => $this->transicionarAEnVenta($credito, $preciosPorBien));
        }

        if ($credito->estado === 'pendiente_conformidad') {
            if ($credito->conformidad_confirmada_at === null) {
                throw new DomainException('Debes registrar la conformidad del notario/abogado antes de enviar el crédito a la tienda.');
            }

            return DB::transaction(fn (): Credito => $this->transicionarAEnVenta($credito, $preciosPorBien));
        }

        throw new DomainException("El crédito debe estar vencido o pendiente de conformidad para enviarse a la tienda (actual: '{$credito->estado}').");
    }

    /**
     * Registra la conformidad del notario/abogado (PDF escaneado) de un
     * crédito en "pendiente_conformidad". No lo envía a la tienda todavía —
     * eso es un paso aparte (enviarATienda()), donde el admin fija el precio
     * de venta por garantía.
     */
    public function confirmarConformidad(Credito $credito, User $actor, UploadedFile $archivo): Credito
    {
        $this->asegurarEstado($credito, 'pendiente_conformidad');

        if ($credito->conformidad_path) {
            Storage::disk('public')->delete($credito->conformidad_path);
        }

        $credito->update([
            'conformidad_path' => $archivo->store("creditos/{$credito->id}/conformidad", 'public'),
            'conformidad_confirmada_at' => now(),
        ]);

        $credito = $credito->fresh();
        $this->notificar($credito);
        $this->notificaciones->enviar(
            $this->hierarchy->controladoresDe($credito)->push($credito->registradoPor),
            new CreditoConformidadRegistradaNotification($credito),
        );

        return $credito;
    }

    private function transicionarAVencido(Credito $credito): Credito
    {
        $credito->update(['estado' => 'vencido']);

        $credito = $credito->fresh();
        $this->notificar($credito);
        $this->notificaciones->enviar(
            $this->hierarchy->controladoresDe($credito)->push($credito->registradoPor),
            new CreditoVencidoNotification($credito),
        );

        return $credito;
    }

    private function transicionarAPendienteConformidad(Credito $credito): Credito
    {
        $credito->update(['estado' => 'pendiente_conformidad']);

        $credito = $credito->fresh();
        $this->notificar($credito);
        $this->notificaciones->enviar(
            $this->hierarchy->controladoresDe($credito)->push($credito->registradoPor),
            new CreditoPendienteConformidadNotification($credito),
        );

        return $credito;
    }

    /**
     * Whether a vencido crédito is eligible for enviarATienda() right now —
     * exposed on index()/show() as `puede_enviar_tienda` so the frontend
     * doesn't have to re-derive the días de espera business rule itself
     * (which would need it to separately resolve the right
     * ConfiguracionCredito row, agencia override vs empresa
     * default).
     */
    public function superaEsperaMora(Credito $credito): bool
    {
        if ($credito->estado !== 'vencido') {
            return false;
        }

        return $this->fechaLimiteEspera($credito) < now()->startOfDay()->toDateString();
    }

    private function fechaLimiteEspera(Credito $credito): string
    {
        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);

        return $credito->fecha_vencimiento->copy()->addDays($configuracion->dias_espera_mora)->toDateString();
    }

    /**
     * @param  array<int, numeric-string|float|int>  $preciosPorBien  keyed by bien id
     */
    private function transicionarAEnVenta(Credito $credito, array $preciosPorBien = []): Credito
    {
        $credito->update(['estado' => 'en_venta']);

        foreach ($this->garantiasDe($credito)->get() as $garantia) {
            $garantia->update([
                'estado' => 'disponible_venta',
                'precio_venta' => $preciosPorBien[$garantia->id] ?? $garantia->precio_venta ?? $garantia->valorizacion,
            ]);
        }

        $credito = $credito->fresh();
        $this->notificar($credito);
        $this->notificaciones->enviar($this->hierarchy->controladoresDe($credito)->push($credito->registradoPor), new CreditoEnVentaNotification($credito));

        return $credito;
    }

    public function calcularMora(Credito $credito): string
    {
        $dias = $credito->dias_en_mora;

        if ($dias === 0) {
            return '0.00';
        }

        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);
        $tasaDiaria = bcdiv((string) $configuracion->tasa_mora_diaria, '100', 4);

        return bcmul(bcmul((string) $credito->monto_prestamo, $tasaDiaria, 4), (string) $dias, 2);
    }

    private function asegurarEstado(Credito $credito, string $esperado): void
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
    private function notificar(Credito $credito): void
    {
        $destinatarios = $this->hierarchy->controladoresDe($credito)
            ->push($credito->registradoPor);

        CreditoActualizado::dispatch($credito, $destinatarios);
    }

    /**
     * Todo cobro (refrendar/liquidar/adendar) es dinero real que el actor
     * recibe físicamente o por yape/plin/transferencia — igual que un
     * billetaje o una inyección de bóveda, debe quedar en SU propia caja
     * para que aparezca en su cierre del día. Falla temprano (antes de la
     * transacción) si no tiene caja aperturada, mismo criterio que
     * desembolsar()/CajaService::registrarMovimiento().
     */
    private function resolverCicloParaCobro(User $actor): CajaCiclo
    {
        $caja = Caja::query()->where('user_id', $actor->id)->first();
        $ciclo = $caja?->cicloAbierto()->first();

        if (! $ciclo) {
            throw new DomainException('Debes aperturar tu caja antes de registrar un cobro.');
        }

        return $ciclo;
    }

    /**
     * @see resolverCicloParaCobro() — crea el ingreso ya dentro de la misma
     * transacción que cambia el estado del crédito, para que ambos queden
     * atómicos (o se registra el cobro Y se actualiza el crédito, o ninguno).
     *
     * Además deja una fila en `cobros` (historial de auditoría que consume el
     * módulo Cobranzas). Todo pago sobre un crédito — refrendo, adenda o
     * liquidación — pasa por aquí, así que es el único punto de escritura.
     *
     * @param  array{operacion: string, interes: string, mora?: string|null, descuento?: string|null, motivo_descuento?: string|null, vuelto?: string, credito_sucesor_id?: int|null}  $detalleCobro
     */
    private function registrarCobroEnCaja(
        CajaCiclo $ciclo,
        User $actor,
        Credito $credito,
        string $monto,
        string $medio,
        ?UploadedFile $comprobante,
        string $concepto,
        array $detalleCobro,
    ): void {
        $movimiento = CajaMovimiento::query()->create([
            'caja_ciclo_id' => $ciclo->id,
            'empresa_id' => $ciclo->empresa_id,
            'tipo' => 'ingreso',
            'monto' => $monto,
            'medio' => $medio,
            'concepto' => $concepto,
            'registrado_por' => $actor->id,
            'fecha_caja' => $ciclo->fecha,
        ]);

        if ($comprobante) {
            $movimiento->fotos()->create([
                'tipo' => 'comprobante',
                'path' => $comprobante->store("caja-movimientos/{$movimiento->id}", 'public'),
            ]);
        }

        Cobro::query()->create([
            'empresa_id' => $credito->empresa_id,
            'cliente_id' => $credito->cliente_id,
            'credito_id' => $credito->id,
            'credito_sucesor_id' => $detalleCobro['credito_sucesor_id'] ?? null,
            'caja_ciclo_id' => $ciclo->id,
            'registrado_por' => $actor->id,
            'operacion' => $detalleCobro['operacion'],
            'monto_pagado' => $monto,
            'medio' => $medio,
            'interes' => $detalleCobro['interes'],
            'mora' => $detalleCobro['mora'] ?? null,
            'descuento' => $detalleCobro['descuento'] ?? null,
            'motivo_descuento' => $detalleCobro['motivo_descuento'] ?? null,
            'vuelto' => $detalleCobro['vuelto'] ?? '0.00',
        ]);

        CajaActualizada::dispatch($ciclo->caja, $ciclo->fresh()->saldoActual());
    }

    /**
     * Variante de registrarCobroEnCaja() para refinanciar(): `$monto` puede
     * ser legítimamente cero (toda la deuda se traslada al nuevo capital,
     * sin que cambie de manos dinero real) — en ese caso no exige caja
     * aperturada ni genera movimiento de caja, solo deja la fila de
     * auditoría en `cobros` (caja_ciclo_id queda null, columna ya nullable).
     *
     * @param  array{interes: string, mora?: string|null, descuento?: string|null, motivo_descuento?: string|null, credito_sucesor_id?: int|null}  $detalleCobro
     */
    private function registrarCobroRefinanciamiento(
        User $actor,
        Credito $credito,
        string $monto,
        string $medio,
        ?UploadedFile $comprobante,
        array $detalleCobro,
    ): void {
        $ciclo = null;

        if (bccomp($monto, '0', 2) > 0) {
            $ciclo = $this->resolverCicloParaCobro($actor);

            $movimiento = CajaMovimiento::query()->create([
                'caja_ciclo_id' => $ciclo->id,
                'empresa_id' => $ciclo->empresa_id,
                'tipo' => 'ingreso',
                'monto' => $monto,
                'medio' => $medio,
                'concepto' => "Refinanciamiento de crédito hipotecario #{$credito->id}",
                'registrado_por' => $actor->id,
                'fecha_caja' => $ciclo->fecha,
            ]);

            if ($comprobante) {
                $movimiento->fotos()->create([
                    'tipo' => 'comprobante',
                    'path' => $comprobante->store("caja-movimientos/{$movimiento->id}", 'public'),
                ]);
            }
        }

        Cobro::query()->create([
            'empresa_id' => $credito->empresa_id,
            'cliente_id' => $credito->cliente_id,
            'credito_id' => $credito->id,
            'credito_sucesor_id' => $detalleCobro['credito_sucesor_id'] ?? null,
            'caja_ciclo_id' => $ciclo?->id,
            'registrado_por' => $actor->id,
            'operacion' => 'refinanciamiento',
            'monto_pagado' => $monto,
            'medio' => $medio,
            'interes' => $detalleCobro['interes'],
            'mora' => $detalleCobro['mora'] ?? null,
            'descuento' => $detalleCobro['descuento'] ?? null,
            'motivo_descuento' => $detalleCobro['motivo_descuento'] ?? null,
            'vuelto' => '0.00',
        ]);

        if ($ciclo) {
            CajaActualizada::dispatch($ciclo->caja, $ciclo->fresh()->saldoActual());
        }
    }
}
