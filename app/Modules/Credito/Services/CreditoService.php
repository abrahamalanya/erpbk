<?php

namespace App\Modules\Credito\Services;

use App\Modules\Caja\Events\CajaActualizada;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Cobranza\Models\CobroCuotaAbono;
use App\Modules\Credito\Events\CreditoActualizado;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Credito\Models\DocumentoCredito;
use App\Modules\Credito\Notifications\CreditoAdendadoNotification;
use App\Modules\Credito\Notifications\CreditoAprobacionRevertidaNotification;
use App\Modules\Credito\Notifications\CreditoAprobadoNotification;
use App\Modules\Credito\Notifications\CreditoCondicionesActualizadasNotification;
use App\Modules\Credito\Notifications\CreditoConformidadRegistradaNotification;
use App\Modules\Credito\Notifications\CreditoCuotaPagadaNotification;
use App\Modules\Credito\Notifications\CreditoCuotasPagadasDiarioNotification;
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
use App\Modules\Credito\Notifications\CreditoVendidoNotification;
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
     * Plazo total (días) para $n cuotas de $tipoCuota. Por defecto es
     * simplemente `DIAS_POR_PERIODO[$tipoCuota] * $n`, igual para los cuatro
     * tipos de crédito — salvo un caso: diario con tipo_cuota semanal usando
     * las 4 cuotas por defecto. Ahí, diario (30×1), quincenal (2×15) y
     * mensual (1×30) ya abarcan exactamente un mes calendario (30 días),
     * pero semanal (4×7=28) queda 2 días corto — así que la última cuota
     * absorbe esos 2 días de más (7, 7, 7, 9 en vez de 7, 7, 7, 7) para que
     * el crédito también abarque el mes completo. Solo aplica a las 4
     * cuotas por defecto: si el asesor elige un número de cuotas propio, se
     * usa la fórmula genérica (confirmado explícitamente con el usuario).
     */
    private function plazoTotalPara(string $tipoCredito, string $tipoCuota, int $n): int
    {
        if ($tipoCredito === 'diario' && $tipoCuota === 'semanal' && $n === self::CUOTAS_POR_TIPO['semanal']) {
            return 30;
        }

        return self::DIAS_POR_PERIODO[$tipoCuota] * $n;
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
            // Diario no genera ninguno de los dos: su único documento de
            // deuda es el pagaré, generado recién al desembolsar (ver
            // desembolsar()) porque necesita el cronograma ya real.
            if ($tipoClave !== 'diario') {
                $this->documentos->generarContrato($credito, $actor);
                $this->documentos->generarDeclaracion($credito, $actor);
            }

            if ($tipo->generaFotosGarantia()) {
                $this->documentos->generarFotos($credito, $actor);
            }

            if ($tipo->generaStickerGarantia()) {
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
     * Corrige tipo_interes / tipo_cuota / monto_prestamo de un crédito que
     * todavía no se desembolsó — mismo alcance que actualizarInteres(): solo
     * mientras está pendiente o aprobado, y solo antes de que exista un
     * cronograma real (cuotas), ya que cambiar cualquiera de estos tres
     * después invalidaría uno ya generado. Cada parámetro es independiente:
     * null deja el valor actual sin tocar, para que el frontend pueda seguir
     * ofreciendo un botón de edición por campo (mismo patrón que
     * actualizarInteres()/actualizarFechaDesembolso()) contra un único
     * endpoint.
     */
    public function actualizarCondiciones(Credito $credito, User $actor, ?string $tipoInteres, ?string $tipoCuota, ?string $montoPrestamo): Credito
    {
        if (! in_array($credito->estado, ['pendiente', 'aprobado'], true)) {
            throw new DomainException('Solo se pueden editar estas condiciones mientras el crédito está pendiente o aprobado.');
        }

        if ($credito->cuotas()->exists()) {
            throw new DomainException('No se pueden editar estas condiciones: el crédito ya tiene un cronograma generado.');
        }

        $nuevoTipoInteres = $tipoInteres ?? $credito->tipo_interes;

        if ($nuevoTipoInteres === 'compuesto' && $credito->tipo_credito !== 'hipotecario') {
            throw new DomainException('Solo los créditos hipotecarios pueden ser de interés compuesto.');
        }

        if ($nuevoTipoInteres === 'compuesto' && $credito->numero_cuotas === null) {
            throw new DomainException('Debes indicar el número de cuotas antes de cambiar a interés compuesto.');
        }

        if ($montoPrestamo !== null && $this->tipos->paraCredito($credito)->limitaMontoPorValorizacionGarantia()) {
            $sumaValorizaciones = $this->garantiasDe($credito)->get()->reduce(
                fn (string $carry, $garantia): string => bcadd($carry, (string) $garantia->valorizacion, 2),
                '0'
            );

            if (bccomp($montoPrestamo, $sumaValorizaciones, 2) > 0) {
                throw new DomainException('El monto del préstamo no puede superar la suma de las valorizaciones de las garantías seleccionadas.');
            }
        }

        $credito->update(array_filter([
            'tipo_interes' => $tipoInteres,
            'tipo_cuota' => $tipoCuota,
            'monto_prestamo' => $montoPrestamo,
        ], fn (?string $valor): bool => $valor !== null));

        $credito = $credito->fresh();
        $this->notificar($credito);
        $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoCondicionesActualizadasNotification($credito));

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
                $nuevaFechaVencimiento = $credito->tipo_interes === 'compuesto'
                    ? $this->fechaCuotaCompuesta($nuevaFecha, $credito->tipo_cuota, 1)
                    : $nuevaFecha->copy()->addDays($credito->plazo_dias);

                $credito->update([
                    'fecha_desembolso' => $nuevaFecha->toDateString(),
                    'fecha_vencimiento' => $nuevaFechaVencimiento->toDateString(),
                ]);

                foreach ($credito->cuotas as $cuota) {
                    $fechaCuota = $credito->tipo_interes === 'compuesto'
                        ? $this->fechaCuotaCompuesta($nuevaFecha, $credito->tipo_cuota, $cuota->numero_cuota)
                        : $nuevaFecha->copy()->addDays($diasPorCuota * $cuota->numero_cuota);

                    $cuota->update(['fecha_vencimiento' => $fechaCuota->toDateString()]);
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
                $nuevaFechaVencimiento = $credito->tipo_interes === 'compuesto'
                    ? $this->fechaCuotaCompuesta($credito->fecha_desembolso, $credito->tipo_cuota, 1)
                    : $credito->fecha_desembolso->copy()->addDays($nuevoPlazoDias);

                $credito->cuotas()->delete();

                $credito->update([
                    'numero_cuotas' => $numeroCuotas,
                    'plazo_dias' => $nuevoPlazoDias,
                    'fecha_vencimiento' => $nuevaFechaVencimiento->toDateString(),
                ]);

                $this->generarCronograma($credito, $numeroCuotas);

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

        }

        return DB::transaction(function () use ($credito, $actor, $ciclo, $esAdenda, $numeroCuotas, $interes, $fecha): Credito {
            // Se valida dentro de la transacción y con el ciclo bloqueado para
            // que dos desembolsos simultáneos no pasen ambos con el mismo saldo.
            if (! $esAdenda) {
                CajaCiclo::query()->whereKey($ciclo->id)->lockForUpdate()->first();

                if (bccomp($credito->monto_prestamo, $ciclo->saldoActual(), 2) > 0) {
                    throw new DomainException('No tienes saldo suficiente en tu caja para desembolsar este crédito.');
                }
            }

            if ($interes !== null) {
                $credito->update(['interes' => $interes]);
            }

            $n = $numeroCuotas ?? $credito->numero_cuotas ?? self::CUOTAS_POR_TIPO[$credito->tipo_cuota];
            $plazoTotal = $this->plazoTotalPara($credito->tipo_credito, $credito->tipo_cuota, $n);

            // Prioridad: fecha explícita del formulario → fecha planificada ya
            // anotada en el crédito (actualizarFechaDesembolso en pendiente) → hoy.
            $fechaDesembolso = $fecha !== null
                ? Carbon::parse($fecha)->startOfDay()
                : ($credito->fecha_desembolso?->copy()->startOfDay() ?? now()->startOfDay());

            // Para interés compuesto, cada snapshot representa UNA sola cuota
            // pendiente por vez (ver pagarCuota()): fecha_vencimiento marca el
            // vencimiento de esa próxima cuota (mes de calendario real si es
            // mensual, ver fechaCuotaCompuesta()), no el fin del plazo
            // completo — así "vencido"/dias_en_mora reaccionan a la cuota
            // inmediata, no a las n cuotas restantes. plazo_dias sí sigue
            // reflejando el plazo total (lo usan los documentos/contrato).
            $fechaVencimiento = $credito->tipo_interes === 'compuesto'
                ? $this->fechaCuotaCompuesta($fechaDesembolso, $credito->tipo_cuota, 1)
                : $fechaDesembolso->copy()->addDays($plazoTotal);

            $credito->update([
                'estado' => 'activo',
                'fecha_desembolso' => $fechaDesembolso->toDateString(),
                'plazo_dias' => $plazoTotal,
                'fecha_vencimiento' => $fechaVencimiento->toDateString(),
            ]);

            if (! $esAdenda) {
                CajaMovimiento::query()->create([
                    'caja_ciclo_id' => $ciclo->id,
                    'empresa_id' => $ciclo->empresa_id,
                    'tipo' => 'egreso',
                    'monto' => $credito->monto_prestamo,
                    'concepto' => "Desembolso de crédito prendario #{$credito->id}",
                    'credito_id' => $credito->id,
                    'registrado_por' => $actor->id,
                    'fecha_caja' => $ciclo->fecha,
                ]);

                CajaActualizada::dispatch($ciclo->caja, $ciclo->fresh()->saldoActual());
            }

            $credito = $credito->fresh();
            $this->generarCronograma($credito, $n, $plazoTotal);

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

            // El pagaré de diario recién se genera acá (no en registrar())
            // porque necesita el cronograma ya real y persistido —
            // generarCronograma() ya corrió un par de líneas arriba.
            if ($credito->tipo_credito === 'diario') {
                $this->documentos->generarPagare($credito, $actor);
            }

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
    private function generarCronograma(Credito $credito, int $n, ?int $plazoTotal = null): void
    {
        $filas = $credito->tipo_interes === 'compuesto'
            ? $this->filasCronogramaCompuesto($credito->fecha_desembolso->copy(), (string) $credito->monto_prestamo, (string) $credito->interes, $credito->tipo_cuota, $n)
            : $this->filasCronograma((string) $credito->monto_prestamo, (string) $credito->interes, $n, self::DIAS_POR_PERIODO[$credito->tipo_cuota], $plazoTotal);

        foreach ($filas as $fila) {
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
     * La cuota TOTAL se redondea al sol entero más cercano (39.99 -> 40.00),
     * para que el cliente pague un monto redondo sin decimales — el capital
     * es siempre el exacto de la amortización (sin tocar), el interés
     * mostrado absorbe el ajuste de redondeo (33.33 + 6.67 = 40.00, en vez
     * de 6.66). Como el capital de la última cuota ya es distinto (absorbe
     * el resto de la amortización), su redondeo la "regulariza" sola, sin
     * un caso especial aparte — confirmado explícitamente con el usuario.
     *
     * `$plazoTotal`, cuando se da, fija los días de la ÚLTIMA cuota en vez de
     * `$diasPorCuota * $n` — usado por diario con tipo_cuota semanal (ver
     * plazoTotalPara()) para que las 4 cuotas por defecto abarquen el mes
     * calendario completo (30 días) en vez de quedar en 28.
     *
     * @return list<array{numero_cuota: int, dias: int, monto_capital: string, monto_interes: string, monto_total: string}>
     */
    private function filasCronograma(string $monto, string $interes, int $n, int $diasPorCuota, ?int $plazoTotal = null): array
    {
        $capitalPorCuota = bcdiv($monto, (string) $n, 2);
        $saldoCapital = $monto;

        $factor = bcmul($monto, $interes, 10);
        $interesNominal = bcdiv(bcmul($factor, (string) $diasPorCuota, 10), '3000', 2);

        $filas = [];

        for ($i = 1; $i <= $n; $i++) {
            $capitalCuota = $i === $n ? $saldoCapital : $capitalPorCuota;
            $totalCuota = $this->bcRoundEntero(bcadd($capitalCuota, $interesNominal, 2));

            $filas[] = [
                'numero_cuota' => $i,
                'dias' => $i === $n ? ($plazoTotal ?? $diasPorCuota * $i) : $diasPorCuota * $i,
                'monto_capital' => $capitalCuota,
                'monto_interes' => bcsub($totalCuota, $capitalCuota, 2),
                'monto_total' => $totalCuota,
            ];

            $saldoCapital = bcsub($saldoCapital, $capitalCuota, 2);
        }

        return $filas;
    }

    /**
     * Redondeo half-up al sol entero más cercano (0 decimales, formateado a
     * 2) — la cuota total de interés simple se muestra así (ver
     * filasCronograma()), para que el cliente pague un monto sin decimales.
     */
    private function bcRoundEntero(string $numero): string
    {
        return bcadd(bcadd($numero, '0.5', 0), '0', 2);
    }

    /**
     * Sistema francés (cuota fija): el interés de cada cuota se calcula
     * sobre el SALDO INSOLUTO (que baja cada cuota), a diferencia de
     * filasCronograma() que siempre cobra interés sobre el monto original
     * completo. La tasa del periodo usa el día-conteo NOMINAL del tipo_cuota
     * (30/7/15/1) — fija toda la vida del crédito, para que la cuota (y cada
     * interés) sea predecible sin importar cuántos días reales tenga el mes
     * calendario de turno (confirmado con el usuario: usar el día real aquí
     * desbalanceaba la última cuota). Las FECHAS de vencimiento sí son mes de
     * calendario real (ver fechaCuotaCompuesta()) — solo cambia qué día cae
     * cada cuota, no cuánto cuesta. El interés se redondea a la décima
     * (bcRoundMoney10() — solo el capital conserva los centavos, confirmado
     * con el usuario) y el capital de cada fila es el resto exacto (cuota
     * fija − interés), para que cada fila cuadre exacto contra la cuota — la
     * última absorbe el saldo insoluto que quede, para que el crédito cierre
     * en cero.
     *
     * @return list<array{numero_cuota: int, dias: int, monto_capital: string, monto_interes: string, monto_total: string}>
     */
    private function filasCronogramaCompuesto(Carbon $fechaBase, string $monto, string $interes, string $tipoCuota, int $n): array
    {
        $r = bcdiv(bcmul($interes, (string) self::DIAS_POR_PERIODO[$tipoCuota], 10), '3000', 10);

        $factor = bcpow(bcadd('1', $r, 10), (string) $n, 10);
        $cuotaFija = $this->bcRoundMoney(bcdiv(bcmul($monto, $r, 10), bcsub('1', bcdiv('1', $factor, 10), 10), 10));

        $saldoInsoluto = $monto;
        $filas = [];

        for ($i = 1; $i <= $n; $i++) {
            $fechaCuota = $this->fechaCuotaCompuesta($fechaBase, $tipoCuota, $i);

            $interesCuota = $this->bcRoundMoney10(bcmul($saldoInsoluto, $r, 10));
            $capitalCuota = $i === $n ? $saldoInsoluto : bcsub($cuotaFija, $interesCuota, 2);
            $totalCuota = $i === $n ? bcadd($capitalCuota, $interesCuota, 2) : $cuotaFija;

            $filas[] = [
                'numero_cuota' => $i,
                'dias' => (int) $fechaBase->diffInDays($fechaCuota),
                'monto_capital' => $capitalCuota,
                'monto_interes' => $interesCuota,
                'monto_total' => $totalCuota,
            ];

            $saldoInsoluto = bcsub($saldoInsoluto, $capitalCuota, 2);
        }

        return $filas;
    }

    /**
     * Redondeo half-up a 2 decimales — a diferencia del resto del módulo
     * (que trunca, bcdiv/bcmul con scale nunca redondea), la cuota fija del
     * sistema francés sí necesita redondeo estándar para que coincida con
     * cualquier tabla de amortización de referencia (calculadora, Excel,
     * otro banco) — confirmado contra un ejemplo exacto que pasó el usuario.
     */
    private function bcRoundMoney(string $numero): string
    {
        return bcadd($numero, '0.005', 2);
    }

    /**
     * Redondeo half-up a la DÉCIMA más cercana (1 decimal), formateado a 2 —
     * el interés de cada cuota compuesta se muestra así (solo el capital
     * conserva los centavos), confirmado explícitamente con el usuario para
     * que la tabla se lea más simple.
     */
    private function bcRoundMoney10(string $numero): string
    {
        return bcadd(bcadd($numero, '0.05', 1), '0', 2);
    }

    /**
     * Fecha de vencimiento de la cuota N.º $numeroCuota de un crédito de
     * interés compuesto, contada desde $fechaBase (fecha de desembolso o de
     * la última cuota pagada). Mensual usa mes de calendario real (28-31
     * días, "15 de cada mes"); las demás frecuencias no tienen variabilidad
     * de calendario, así que siguen siendo un múltiplo fijo de días.
     */
    private function fechaCuotaCompuesta(Carbon $fechaBase, string $tipoCuota, int $numeroCuota): Carbon
    {
        return $tipoCuota === 'mensual'
            ? $fechaBase->copy()->addMonthsNoOverflow($numeroCuota)
            : $fechaBase->copy()->addDays(self::DIAS_POR_PERIODO[$tipoCuota] * $numeroCuota);
    }

    /**
     * Cronograma tentativo para mostrar al registrar el crédito, cuando aún
     * no hay cuotas persistidas: usa la fecha de hoy como desembolso y el
     * número de cuotas por defecto del tipo (misma fórmula que
     * generarCronograma()). No toca la base de datos.
     *
     * @return array{fecha_base: string, plazo_dias: int, cuotas: list<array{numero_cuota: int, fecha_vencimiento: string, monto_capital: string, monto_interes: string, monto_total: string}>}
     */
    public function previsualizarCronograma(string $monto, string $interes, string $tipoCuota, ?int $numeroCuotas = null, string $tipoInteres = 'simple', string $tipoCredito = 'prendario'): array
    {
        if (! isset(self::CUOTAS_POR_TIPO[$tipoCuota])) {
            throw new DomainException("Tipo de cuota inválido: {$tipoCuota}");
        }

        $n = $numeroCuotas ?? self::CUOTAS_POR_TIPO[$tipoCuota];
        $diasPorCuota = self::DIAS_POR_PERIODO[$tipoCuota];
        $plazoTotal = $this->plazoTotalPara($tipoCredito, $tipoCuota, $n);
        $base = now()->startOfDay();

        $filas = $tipoInteres === 'compuesto'
            ? $this->filasCronogramaCompuesto($base, $monto, $interes, $tipoCuota, $n)
            : $this->filasCronograma($monto, $interes, $n, $diasPorCuota, $plazoTotal);

        $cuotas = array_map(fn (array $fila): array => [
            'numero_cuota' => $fila['numero_cuota'],
            'fecha_vencimiento' => $base->copy()->addDays($fila['dias'])->toDateString(),
            'monto_capital' => $fila['monto_capital'],
            'monto_interes' => $fila['monto_interes'],
            'monto_total' => $fila['monto_total'],
        ], $filas);

        return [
            'fecha_base' => $base->toDateString(),
            'plazo_dias' => $plazoTotal,
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
        $this->asegurarSinCuotasPagadas($credito, 'refrendar');

        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede refrendar un crédito activo o vencido.');
        }

        if ($credito->tipo_interes === 'compuesto') {
            throw new DomainException('Este crédito es de interés compuesto: usa "pagar cuota" en vez de refrendar.');
        }

        if ($credito->tipo_credito === 'diario') {
            throw new DomainException('Los créditos diarios no se refrendan: usa "pagar cuotas" para pagar cuotas pendientes.');
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

        $tipo = $this->tipos->paraCredito($credito);
        $modeloGarantia = $tipo->garantiaModelo();
        $ciclo = $this->resolverCicloParaCobro($actor);
        $estadoAnterior = $credito->estado;

        return DB::transaction(function () use ($credito, $actor, $siguienteNumero, $nuevoCapital, $interes, $mora, $descuento, $motivoDescuento, $abonoCapital, $ciclo, $montoPagado, $medio, $comprobante, $modeloGarantia, $estadoAnterior, $tipo): Credito {
            $credito->update(['estado' => 'refrendado']);

            // Conserva el numero_cuotas del crédito original (p. ej. un
            // vehicular/hipotecario registrado con un cronograma de varias
            // cuotas) — antes se perdía en cada refrendo, cayendo siempre al
            // default de 1 cuota del tipo_cuota y bloqueando para siempre el
            // pago por cuotas / pago a cuenta en el sucesor.
            $n = $credito->numero_cuotas ?? self::CUOTAS_POR_TIPO[$credito->tipo_cuota];
            $plazoTotal = $this->plazoTotalPara($credito->tipo_credito, $credito->tipo_cuota, $n);

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
                'numero_cuotas' => $credito->numero_cuotas,
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

            if ($tipo->generaFotosGarantia()) {
                $this->documentos->generarFotos($nuevo, $actor);
            }

            if ($credito->tipo_credito === 'hipotecario') {
                $this->documentos->generarFichaSocioeconomica($nuevo, $actor);
                $this->documentos->generarNotificacionPago($nuevo, $actor);
                $this->documentos->generarAvisoPrejudicial($nuevo, $actor);
                $this->documentos->generarExpediente($nuevo, $actor);
            } elseif ($tipo->generaStickerGarantia()) {
                $this->documentos->generarSticker($nuevo, $actor);
            }

            $nuevo = $nuevo->fresh(['bienes']);
            $this->generarCronograma($nuevo, $n, $plazoTotal);
            $cobro = $this->registrarCobroEnCaja($ciclo, $actor, $credito, $montoPagado, $medio, $comprobante, "Refrendo de crédito prendario #{$credito->id}", [
                'operacion' => 'refrendo',
                'credito_estado_anterior' => $estadoAnterior,
                'interes' => $interes,
                'mora' => $mora,
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'credito_sucesor_id' => $nuevo->id,
            ]);

            $this->documentos->generarVoucherPago($credito, $actor, [
                'cobro_id' => $cobro->id,
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

            return $this->conCobro($nuevo, $cobro);
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
        $this->asegurarSinCuotasPagadas($credito, 'adendar');

        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede hacer una adenda a un crédito activo o vencido.');
        }

        if ($credito->tipo_interes === 'compuesto') {
            throw new DomainException('Este crédito es de interés compuesto: usa "pagar cuota" en vez de adendar.');
        }

        if ($credito->tipo_credito === 'diario') {
            throw new DomainException('Los créditos diarios no se adendan: usa "pagar cuotas" para pagar cuotas pendientes.');
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
        $tipo = $this->tipos->paraCredito($credito);
        $modeloGarantia = $tipo->garantiaModelo();
        $ciclo = $this->resolverCicloParaCobro($actor);
        $estadoAnterior = $credito->estado;

        return DB::transaction(function () use ($credito, $actor, $nuevoInteres, $nuevoTipoCuota, $nuevoCapital, $interes, $mora, $descuento, $motivoDescuento, $abonoCapital, $configuracion, $ciclo, $montoPagado, $medio, $comprobante, $modeloGarantia, $estadoAnterior, $tipo): Credito {
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
                // Conserva el numero_cuotas del crédito original — igual
                // que refrendar(), así el desembolso del sucesor pendiente no
                // cae al default de 1 cuota del tipo_cuota (ver
                // desembolsar()); si el admin cambia tipo_cuota o quiere otro
                // número, puede indicarlo de nuevo explícitamente al desembolsar.
                'numero_cuotas' => $credito->numero_cuotas,
                'plazo_dias' => $configuracion->plazo_dias,
                'estado' => 'pendiente',
            ]);

            $nuevo->garantiasComo($modeloGarantia)->attach($this->garantiasDe($credito)->get()->pluck('id'));

            $this->documentos->generarContrato($nuevo, $actor);
            $this->documentos->generarDeclaracion($nuevo, $actor);

            if ($tipo->generaFotosGarantia()) {
                $this->documentos->generarFotos($nuevo, $actor);
            }

            if ($credito->tipo_credito === 'hipotecario') {
                $this->documentos->generarFichaSocioeconomica($nuevo, $actor);
                $this->documentos->generarNotificacionPago($nuevo, $actor);
                $this->documentos->generarAvisoPrejudicial($nuevo, $actor);
                $this->documentos->generarExpediente($nuevo, $actor);
            } elseif ($tipo->generaStickerGarantia()) {
                $this->documentos->generarSticker($nuevo, $actor);
            }

            $nuevo = $nuevo->fresh(['bienes']);
            $cobro = $this->registrarCobroEnCaja($ciclo, $actor, $credito, $montoPagado, $medio, $comprobante, "Adenda de crédito prendario #{$credito->id}", [
                'operacion' => 'adenda',
                'credito_estado_anterior' => $estadoAnterior,
                'interes' => $interes,
                'mora' => $mora,
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'credito_sucesor_id' => $nuevo->id,
            ]);

            $this->documentos->generarVoucherPago($credito, $actor, [
                'cobro_id' => $cobro->id,
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

            return $this->conCobro($nuevo, $cobro);
        });
    }

    /**
     * Paga la cuota fija correspondiente de un crédito de interés compuesto
     * (sistema francés) — el equivalente de refrendar()/adendar() para este
     * tipo de crédito, que no admite ninguno de esos dos (el capital ya se
     * amortiza por cuota, no tiene sentido "renovar" pagando solo interés).
     * Cierra el crédito actual y crea un sucesor con el saldo insoluto ya
     * descontado, salvo que sea la ÚLTIMA cuota: ahí no hay sucesor, el
     * crédito pasa a liquidado_pendiente igual que liquidar() (queda a la
     * espera de la firma de la devolución de la garantía).
     *
     * Recalcular la cuota fija sobre el saldo insoluto y las cuotas
     * restantes reproduce el mismo monto (propiedad matemática de las
     * anualidades: una cuota fija calculada sobre P, r, n da la misma cuota
     * si se recalcula sobre el saldo remanente tras k periodos con n-k
     * periodos restantes, mismo r) — módulo redondeo, así el monto de cuota
     * se mantiene estable durante toda la vida del crédito.
     */
    public function pagarCuota(Credito $credito, User $actor, string $montoPagado, string $medio, ?UploadedFile $comprobante, int $numeroCuotas = 1): Credito
    {
        if ($credito->tipo_interes !== 'compuesto') {
            throw new DomainException('Solo los créditos de interés compuesto se pagan por cuota — usa refrendar o liquidar.');
        }

        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede pagar una cuota de un crédito activo o vencido.');
        }

        $cuotasAPagar = $credito->cuotas()->orderBy('numero_cuota')->limit($numeroCuotas)->get();

        if ($cuotasAPagar->isEmpty()) {
            throw new DomainException('Este crédito no tiene un cronograma de cuotas generado.');
        }

        if ($cuotasAPagar->count() < $numeroCuotas) {
            throw new DomainException("Solo quedan {$cuotasAPagar->count()} cuota(s) pendiente(s), no se pueden pagar {$numeroCuotas}.");
        }

        $cuota = $cuotasAPagar->last();
        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);
        $mora = $cuotasAPagar->reduce(fn (string $carry, CuotaCredito $c): string => bcadd($carry, $this->moraDeCuota($c, $configuracion), 2), '0.00');
        $totalCuotas = $cuotasAPagar->reduce(fn (string $carry, CuotaCredito $c): string => bcadd($carry, (string) $c->monto_total, 2), '0.00');
        $capitalCuotas = $cuotasAPagar->reduce(fn (string $carry, CuotaCredito $c): string => bcadd($carry, (string) $c->monto_capital, 2), '0.00');
        $montoMinimo = bcadd($totalCuotas, $mora, 2);

        if (bccomp($montoPagado, $montoMinimo, 2) < 0) {
            throw new DomainException("El monto pagado ({$montoPagado}) es menor a la cuota + mora a pagar calculada ({$montoMinimo}).");
        }

        $abonoExtra = bcsub($montoPagado, $montoMinimo, 2);
        $abonoCapital = bcadd($capitalCuotas, $abonoExtra, 2);
        $nuevoCapital = bcsub((string) $credito->monto_prestamo, $abonoCapital, 2);
        $nuevoNumeroCuotas = $credito->numero_cuotas - $numeroCuotas;

        if ($nuevoNumeroCuotas > 0 && bccomp($nuevoCapital, '0', 2) <= 0) {
            throw new DomainException('El abono ingresado cancela todo el saldo pendiente; selecciona Liquidar para cancelar el crédito.');
        }

        $modeloGarantia = $this->tipos->paraCredito($credito)->garantiaModelo();
        $ciclo = $this->resolverCicloParaCobro($actor);
        $estadoAnterior = $credito->estado;

        return DB::transaction(function () use ($credito, $actor, $cuota, $nuevoNumeroCuotas, $nuevoCapital, $mora, $abonoCapital, $ciclo, $montoPagado, $medio, $comprobante, $modeloGarantia, $estadoAnterior): Credito {
            if ($nuevoNumeroCuotas === 0) {
                // Última cuota: mismo final que liquidar() — el pago ya
                // canceló todo el saldo, solo falta la firma de la devolución
                // para liberar la garantía (confirmarLiquidacionSiCorresponde()
                // no cambia, reacciona igual sin importar cómo se llegó aquí).
                $credito->update(['estado' => 'liquidado_pendiente']);

                $credito = $credito->fresh(['inmuebles']);
                $cobro = $this->registrarCobroEnCaja($ciclo, $actor, $credito, $montoPagado, $medio, $comprobante, "Pago de última cuota — crédito hipotecario #{$credito->id}", [
                    'operacion' => 'pago_cuota',
                    'credito_estado_anterior' => $estadoAnterior,
                    'interes' => bcsub($montoPagado, bcadd($abonoCapital, $mora, 2), 2),
                    'mora' => $mora,
                ]);
                $this->documentos->generarDevolucion($credito, $actor);

                $this->documentos->generarVoucherPago($credito, $actor, [
                    'cobro_id' => $cobro->id,
                    'operacion' => 'pago_cuota',
                    'monto_pagado' => $montoPagado,
                    'medio' => $medio,
                    'mora' => $mora,
                    'abono_capital' => $abonoCapital,
                    'saldo_capital' => '0.00',
                    'credito_id' => $credito->id,
                    'fecha' => now()->toDateString(),
                ]);

                $this->notificar($credito);

                return $this->conCobro($credito->fresh(['inmuebles', 'documentos']), $cobro);
            }

            $credito->update(['estado' => 'cuota_pagada']);

            $diasPorCuota = self::DIAS_POR_PERIODO[$credito->tipo_cuota];
            // Ancla el sucesor al vencimiento PROGRAMADO de la cuota recién
            // pagada (no a "hoy") — así "15 de cada mes" se mantiene aunque
            // el cliente pague unos días antes o tarde, y generarCronograma()
            // (que arranca desde fecha_desembolso) genera las cuotas
            // restantes con las mismas fechas ancladas.
            $fechaDesembolso = $cuota->fecha_vencimiento->copy();

            $nuevo = Credito::query()->create([
                'empresa_id' => $credito->empresa_id,
                'agencia_id' => $credito->agencia_id,
                'tipo_credito' => $credito->tipo_credito,
                'cliente_id' => $credito->cliente_id,
                'aval_id' => $credito->aval_id,
                'aval_2_id' => $credito->aval_2_id,
                'supervisado_por' => $credito->supervisado_por,
                'registrado_por' => $credito->registrado_por,
                'pago_cuota_de_credito_id' => $credito->id,
                'monto_prestamo' => $nuevoCapital,
                'interes' => $credito->interes,
                'tipo_interes' => 'compuesto',
                'tipo_cuota' => $credito->tipo_cuota,
                'numero_cuotas' => $nuevoNumeroCuotas,
                'plazo_dias' => $diasPorCuota * $nuevoNumeroCuotas,
                'estado' => 'activo',
                'fecha_desembolso' => $fechaDesembolso->toDateString(),
                'fecha_vencimiento' => $this->fechaCuotaCompuesta($fechaDesembolso, $credito->tipo_cuota, 1)->toDateString(),
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
            $this->generarCronograma($nuevo, $nuevoNumeroCuotas);
            $cobro = $this->registrarCobroEnCaja($ciclo, $actor, $credito, $montoPagado, $medio, $comprobante, "Pago de cuota — crédito hipotecario #{$credito->id}", [
                'operacion' => 'pago_cuota',
                'credito_estado_anterior' => $estadoAnterior,
                'interes' => bcsub($montoPagado, bcadd($abonoCapital, $mora, 2), 2),
                'mora' => $mora,
                'credito_sucesor_id' => $nuevo->id,
            ]);

            $this->documentos->generarVoucherPago($credito, $actor, [
                'cobro_id' => $cobro->id,
                'operacion' => 'pago_cuota',
                'monto_pagado' => $montoPagado,
                'medio' => $medio,
                'mora' => $mora,
                'abono_capital' => $abonoCapital,
                'saldo_capital' => $nuevoCapital,
                'credito_id' => $credito->id,
                'credito_sucesor_id' => $nuevo->id,
                'fecha' => now()->toDateString(),
            ]);

            $this->notificar($nuevo);
            $this->notificaciones->enviar(collect([$nuevo->registradoPor]), new CreditoCuotaPagadaNotification($nuevo));

            return $this->conCobro($nuevo, $cobro);
        });
    }

    /**
     * Mora acumulada por UNA cuota vencida individual — la usa el pago de
     * cuotas de un crédito diario, donde cada CuotaCredito acumula su propia
     * mora desde SU fecha_vencimiento, sin esperar a que venza el crédito
     * completo (a diferencia de calcularMora(), que solo penaliza una vez
     * que el crédito ENTERO cae en estado 'vencido').
     */
    private function moraDeCuota(CuotaCredito $cuota, ConfiguracionCredito $configuracion): string
    {
        $vencimiento = $cuota->fecha_vencimiento->copy()->startOfDay();
        $hoy = now()->startOfDay();

        if ($vencimiento->gte($hoy)) {
            return '0.00';
        }

        $dias = (int) $vencimiento->diffInDays($hoy);
        $tasaDiaria = bcdiv((string) $configuracion->tasa_mora_diaria, '100', 4);

        $moraAcumulada = bcmul(bcmul((string) $cuota->monto_total, $tasaDiaria, 4), (string) $dias, 2);
        $moraPendiente = bcsub($moraAcumulada, (string) ($cuota->mora_pagada ?? 0), 2);

        return bccomp($moraPendiente, '0', 2) > 0 ? $moraPendiente : '0.00';
    }

    /**
     * Suma la mora individual (moraDeCuota()) de todas las cuotas pendientes
     * de un crédito diario — reemplaza a calcularMora() (mora "a nivel
     * crédito completo") para este tipo, ya que sus cuotas vencen y acumulan
     * mora una por una mucho antes de que el plazo completo del crédito lo
     * haga.
     */
    private function moraPendienteDiario(Credito $credito): string
    {
        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);

        return $credito->cuotas()->pendientes()->get()
            ->reduce(fn (string $carry, CuotaCredito $cuota): string => bcadd($carry, $this->moraDeCuota($cuota, $configuracion), 2), '0.00');
    }

    /**
     * Si el crédito admite pagarse por cuotas: cualquier tipo de interés
     * simple (prendario, vehicular, hipotecario simple, diario) lo admite
     * con solo tener una cuota pendiente — así un abono parcial (pago a
     * cuenta) queda registrado en el MISMO crédito, sin generar un sucesor
     * ni un contrato nuevo como sí hace refrendar/adendar. El compuesto
     * (solo hipotecario) sigue exigiendo más de una cuota pendiente, porque
     * con una sola ya se cubre con "pagar cuota"/liquidar.
     */
    public function admitePagoPorCuotas(Credito $credito): bool
    {
        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            return false;
        }

        $pendientes = $credito->cuotas()->pendientes()->count();

        return match (true) {
            $credito->tipo_interes === 'compuesto' => $pendientes > 1,
            default => $pendientes >= 1,
        };
    }

    /**
     * Adjunta al crédito los montos sugeridos y las operaciones que admite
     * hoy (`permite_pago_cuotas`, `permite_refrendo`), para prellenar el
     * formulario de cobro — lo comparten el detalle del crédito y la lista de
     * créditos pendientes de Cobranzas, para que ambos ofrezcan lo mismo.
     */
    public function adjuntarMontosSugeridos(Credito $credito): void
    {
        $credito->setAttribute('monto_liquidacion_sugerido', $this->calcularMontoLiquidacion($credito));

        $admitePagoPorCuotas = $this->admitePagoPorCuotas($credito);
        $esCompuesto = $credito->tipo_interes === 'compuesto';
        $permiteRefrendo = $credito->tipo_credito !== 'diario' && ! $esCompuesto && ! $credito->cuotas()->pagadas()->exists();

        $credito->setAttribute('permite_pago_cuotas', $admitePagoPorCuotas);
        $credito->setAttribute('permite_refrendo', $permiteRefrendo);

        if ($admitePagoPorCuotas) {
            $credito->setAttribute('monto_pago_cuotas_sugerido', $this->calcularMontoPagoCuotas($credito, 1));
        }

        if ($esCompuesto && $credito->tipo_credito !== 'diario') {
            $credito->setAttribute('monto_pago_cuota_sugerido', $this->calcularMontoPagoCuota($credito));
        }

        if ($permiteRefrendo) {
            $credito->setAttribute('monto_refrendo_sugerido', $this->calcularMontoRefrendo($credito));
        }
    }

    /**
     * Cuotas pendientes de un crédito más la configuración de mora — valida
     * que admita pago por cuotas. Compartido por el preview de "N cuotas" y
     * por la amortización por monto.
     *
     * @return array{0: Collection<int, CuotaCredito>, 1: ConfiguracionCredito}
     */
    private function cuotasPendientesParaPago(Credito $credito): array
    {
        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede pagar cuotas de un crédito activo o vencido.');
        }

        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);
        $pendientes = $credito->cuotas()->pendientes()->orderBy('numero_cuota')->get();

        if ($pendientes->isEmpty()) {
            throw new DomainException('Este crédito no tiene cuotas pendientes.');
        }

        if (! $this->admitePagoPorCuotas($credito)) {
            throw new DomainException('Este crédito no admite pago por cuotas: necesita más de una cuota pendiente — usa refrendar, pagar cuota o liquidar.');
        }

        return [$pendientes, $configuracion];
    }

    /**
     * Refrendar/adendar/refinanciar reinician el crédito sobre el capital
     * original, así que no conviven con cuotas ya pagadas dentro del mismo
     * crédito (pago por cuotas): desde ahí solo se sigue pagando cuotas o se
     * liquida.
     */
    private function asegurarSinCuotasPagadas(Credito $credito, string $operacion): void
    {
        if ($credito->cuotas()->pagadas()->exists()) {
            throw new DomainException("No se puede {$operacion}: este crédito ya tiene cuotas pagadas — usa pagar cuotas o liquidar.");
        }
    }

    /**
     * Arma el detalle de lo que se aplicaría a una cuota: su mora vigente
     * (ver moraDeCuota()), lo que se abonaría y lo que le quedaría por
     * pagar.
     *
     * @return array{numero_cuota: int, fecha_vencimiento: string, monto_total: string, mora: string, abono: string, saldo_restante: string, completa: bool}
     */
    private function detalleAplicacionCuota(CuotaCredito $cuota, string $mora, string $abono): array
    {
        $saldo = bcsub((string) $cuota->monto_total, (string) $cuota->monto_abonado, 2);
        $saldoRestante = bcsub($saldo, $abono, 2);

        return [
            'numero_cuota' => $cuota->numero_cuota,
            'fecha_vencimiento' => $cuota->fecha_vencimiento->toDateString(),
            'monto_total' => (string) $cuota->monto_total,
            'mora' => $mora,
            'abono' => $abono,
            'saldo_restante' => $saldoRestante,
            'completa' => bccomp($saldoRestante, '0', 2) === 0,
        ];
    }

    /**
     * @param  list<array{numero_cuota: int, fecha_vencimiento: string, monto_total: string, mora: string, abono: string, saldo_restante: string, completa: bool}>  $detalle
     * @return array{cuotas: list<array{numero_cuota: int, fecha_vencimiento: string, monto_total: string, mora: string, abono: string, saldo_restante: string, completa: bool}>, monto_cuotas: string, mora: string, total: string, vuelto: string, es_ultima_cuota: bool}
     */
    private function resumenAplicacion(array $detalle, string $vuelto, int $pendientesTotal): array
    {
        $montoCuotas = array_reduce($detalle, fn (string $carry, array $c): string => bcadd($carry, $c['abono'], 2), '0.00');
        $mora = array_reduce($detalle, fn (string $carry, array $c): string => bcadd($carry, $c['mora'], 2), '0.00');
        $completas = count(array_filter($detalle, fn (array $c): bool => $c['completa']));

        return [
            'cuotas' => $detalle,
            'monto_cuotas' => $montoCuotas,
            'mora' => $mora,
            'total' => bcadd($montoCuotas, $mora, 2),
            'vuelto' => $vuelto,
            'es_ultima_cuota' => $completas === $pendientesTotal,
        ];
    }

    /**
     * Preview de cuánto cuesta pagar las próximas `$numeroCuotas` cuotas
     * pendientes (siempre las más antiguas, consecutivas) de un crédito
     * diario, cada una por su saldo restante + mora. Lo consume tanto el
     * endpoint de preview como pagarCuotas().
     *
     * @return array{cuotas: list<array{numero_cuota: int, fecha_vencimiento: string, monto_total: string, mora: string, abono: string, saldo_restante: string, completa: bool}>, monto_cuotas: string, mora: string, total: string, vuelto: string, es_ultima_cuota: bool}
     */
    public function calcularMontoPagoCuotas(Credito $credito, int $numeroCuotas): array
    {
        [$pendientes, $configuracion] = $this->cuotasPendientesParaPago($credito);

        if ($numeroCuotas > $pendientes->count()) {
            throw new DomainException("Solo quedan {$pendientes->count()} cuota(s) pendiente(s), no se pueden pagar {$numeroCuotas}.");
        }

        $detalle = $pendientes->take($numeroCuotas)->map(function (CuotaCredito $cuota) use ($configuracion): array {
            $saldo = bcsub((string) $cuota->monto_total, (string) $cuota->monto_abonado, 2);

            return $this->detalleAplicacionCuota($cuota, $this->moraDeCuota($cuota, $configuracion), $saldo);
        })->values()->all();

        return $this->resumenAplicacion($detalle, '0.00', $pendientes->count());
    }

    /**
     * Amortización de un crédito diario: reparte `$monto` sobre las cuotas
     * pendientes de la más antigua a la más nueva — primero la mora vigente
     * de cada cuota, luego su saldo. Las que el monto alcanza a cubrir
     * quedan pagadas; si sobra una fracción, la siguiente cuota queda con un
     * abono parcial (adelanto). Solo hay `vuelto` cuando el monto supera la
     * deuda completa del cronograma.
     *
     * @return array{cuotas: list<array{numero_cuota: int, fecha_vencimiento: string, monto_total: string, mora: string, abono: string, saldo_restante: string, completa: bool}>, monto_cuotas: string, mora: string, total: string, vuelto: string, es_ultima_cuota: bool}
     */
    public function calcularAmortizacion(Credito $credito, string $monto): array
    {
        if ($credito->tipo_interes === 'compuesto') {
            throw new DomainException('Los créditos de interés compuesto solo se pagan por cuotas completas.');
        }

        [$pendientes, $configuracion] = $this->cuotasPendientesParaPago($credito);

        $restante = $monto;
        $detalle = [];

        foreach ($pendientes as $cuota) {
            if (bccomp($restante, '0', 2) <= 0) {
                break;
            }

            $mora = $this->moraDeCuota($cuota, $configuracion);
            $moraAplicada = bccomp($restante, $mora, 2) >= 0 ? $mora : $restante;
            $restante = bcsub($restante, $moraAplicada, 2);

            $saldo = bcsub((string) $cuota->monto_total, (string) $cuota->monto_abonado, 2);
            $abono = bccomp($restante, $saldo, 2) >= 0 ? $saldo : $restante;
            $restante = bcsub($restante, $abono, 2);

            $fila = $this->detalleAplicacionCuota($cuota, $moraAplicada, $abono);
            $fila['completa'] = $fila['completa'] && bccomp($moraAplicada, $mora, 2) === 0;
            $detalle[] = $fila;
        }

        return $this->resumenAplicacion($detalle, $restante, $pendientes->count());
    }

    /**
     * Paga un crédito diario dentro del MISMO crédito — a diferencia de
     * refrendar()/adendar()/pagarCuota(), no encadena un crédito sucesor.
     * Con `$numeroCuotas` paga esas cuotas más antiguas completas (el
     * excedente es vuelto); sin él amortiza `$montoPagado` (ver
     * calcularAmortizacion()), dejando la última cuota tocada con un
     * abono parcial si el monto no alcanza a cubrirla. Si con este pago ya
     * no queda ninguna cuota pendiente, el crédito pasa a liquidado igual
     * que liquidar() (carta de no adeudo, sin acta de devolución).
     */
    public function pagarCuotas(Credito $credito, User $actor, ?int $numeroCuotas, string $montoPagado, string $medio, ?UploadedFile $comprobante): Credito
    {
        if ($credito->tipo_interes === 'compuesto') {
            if ($numeroCuotas === null) {
                throw new DomainException('Los créditos de interés compuesto solo se pagan por cuotas completas: indica cuántas cuotas pagar.');
            }

            return $this->pagarCuota($credito, $actor, $montoPagado, $medio, $comprobante, $numeroCuotas);
        }

        if ($numeroCuotas !== null) {
            $calculo = $this->calcularMontoPagoCuotas($credito, $numeroCuotas);

            if (bccomp($montoPagado, $calculo['total'], 2) < 0) {
                throw new DomainException("El monto pagado ({$montoPagado}) es menor al total de las cuotas + mora a pagar calculado ({$calculo['total']}).");
            }

            $calculo['vuelto'] = bcsub($montoPagado, $calculo['total'], 2);
        } else {
            $calculo = $this->calcularAmortizacion($credito, $montoPagado);
        }

        $vuelto = $calculo['vuelto'];
        $ciclo = $this->resolverCicloParaCobro($actor);
        $estadoAnterior = $credito->estado;
        $esUltimaCuota = $calculo['es_ultima_cuota'];
        $cuotasCompletas = count(array_filter($calculo['cuotas'], fn (array $c): bool => $c['completa']));

        $esDiario = $credito->tipo_credito === 'diario';

        return DB::transaction(function () use ($credito, $actor, $calculo, $ciclo, $montoPagado, $medio, $comprobante, $estadoAnterior, $vuelto, $esUltimaCuota, $cuotasCompletas, $esDiario): Credito {
            if ($esUltimaCuota) {
                // Mismo final que liquidar(): el diario no tiene garantía
                // física, salta directo a 'liquidado' + carta de no adeudo;
                // los demás quedan 'liquidado_pendiente' a la espera de la
                // firma del acta de devolución (ver más abajo).
                $credito->update(['estado' => $esDiario ? 'liquidado' : 'liquidado_pendiente']);

                if ($esDiario) {
                    $modeloGarantia = $this->tipos->paraCredito($credito)->garantiaModelo();
                    $modeloGarantia::query()->whereIn('id', $this->garantiasDe($credito)->get()->pluck('id'))->update(['estado' => 'recuperado']);

                    $this->documentos->generarCartaNoAdeudo($credito, $actor);
                }
            }

            $cobro = $this->registrarCobroEnCaja($ciclo, $actor, $credito, $montoPagado, $medio, $comprobante, "Pago de cuotas — crédito {$credito->tipo_credito} #{$credito->id}", [
                'operacion' => 'pago_cuotas_diario',
                'credito_estado_anterior' => $estadoAnterior,
                'interes' => $calculo['monto_cuotas'],
                'mora' => $calculo['mora'],
                'vuelto' => $vuelto,
            ]);

            foreach ($calculo['cuotas'] as $fila) {
                $cuota = $credito->cuotas()->where('numero_cuota', $fila['numero_cuota'])->firstOrFail();

                $cuota->update([
                    'monto_abonado' => bcadd((string) $cuota->monto_abonado, $fila['abono'], 2),
                    'mora_pagada' => bcadd((string) ($cuota->mora_pagada ?? 0), $fila['mora'], 2),
                    'pagada_at' => $fila['completa'] ? now() : $cuota->pagada_at,
                    'cobro_id' => $fila['completa'] ? $cobro->id : $cuota->cobro_id,
                ]);

                $cobro->abonosCuotas()->create([
                    'cuota_credito_id' => $cuota->id,
                    'monto_mora' => $fila['mora'],
                    'monto_cuota' => $fila['abono'],
                    'completa' => $fila['completa'],
                ]);
            }

            if ($esUltimaCuota && ! $esDiario) {
                $this->documentos->generarDevolucion($credito->fresh(['bienes', 'vehiculos', 'inmuebles']), $actor);
            }

            $this->documentos->generarVoucherPago($credito, $actor, [
                'cobro_id' => $cobro->id,
                'operacion' => 'pago_cuotas_diario',
                'monto_pagado' => $montoPagado,
                'medio' => $medio,
                'cuotas' => $calculo['cuotas'],
                'mora' => $calculo['mora'],
                'total' => $calculo['total'],
                'vuelto' => $vuelto,
                'credito_id' => $credito->id,
                'fecha' => now()->toDateString(),
            ]);

            $credito = $credito->fresh($esDiario ? ['garantiasDiarias'] : ['documentos']);
            $this->notificar($credito);

            if (! $esUltimaCuota) {
                $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoCuotasPagadasDiarioNotification($credito, $cuotasCompletas));
            } elseif ($esDiario) {
                $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoLiquidadoNotification($credito));
            }

            return $this->conCobro($credito, $cobro);
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
        $this->asegurarSinCuotasPagadas($credito, 'refinanciar');

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
        $estadoAnterior = $credito->estado;

        return DB::transaction(function () use ($credito, $actor, $nuevoCapital, $liquidacion, $descuento, $motivoDescuento, $deudaTotal, $configuracion, $montoPagado, $medio, $comprobante, $modeloGarantia, $estadoAnterior): Credito {
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
                'tipo_interes' => $credito->tipo_interes,
                'tipo_cuota' => $credito->tipo_cuota,
                // Conserva el numero_cuotas del crédito original — mismo
                // motivo que en refrendar()/adendar().
                'numero_cuotas' => $credito->numero_cuotas,
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
            $cobro = $this->registrarCobroRefinanciamiento($actor, $credito, $montoPagado, $medio, $comprobante, [
                'interes' => $liquidacion['interes'],
                'credito_estado_anterior' => $estadoAnterior,
                'mora' => $liquidacion['mora'],
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'credito_sucesor_id' => $nuevo->id,
            ]);

            $this->documentos->generarVoucherPago($credito, $actor, [
                'cobro_id' => $cobro->id,
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

            return $this->conCobro($nuevo, $cobro);
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
        $estadoAnterior = $credito->estado;

        return DB::transaction(function () use ($credito, $actor, $ciclo, $montoPagado, $medio, $comprobante, $liquidacion, $descuento, $motivoDescuento, $montoCalculado, $estadoAnterior): Credito {
            // Diario no tiene garantía física que devolver (placeholder
            // invisible) — a diferencia de los demás tipos, salta directo a
            // 'liquidado' + carta de no adeudo, sin pasar por
            // 'liquidado_pendiente' ni pedir firma de un acta de devolución
            // que no aplica (mismo criterio que pagarCuotas()).
            $esDiario = $credito->tipo_credito === 'diario';

            $credito->update(['estado' => $esDiario ? 'liquidado' : 'liquidado_pendiente']);

            $credito = $credito->fresh(['bienes']);
            $cobro = $this->registrarCobroEnCaja($ciclo, $actor, $credito, $montoPagado, $medio, $comprobante, "Liquidación de crédito prendario #{$credito->id}", [
                'operacion' => 'liquidacion',
                'credito_estado_anterior' => $estadoAnterior,
                'interes' => $liquidacion['interes'],
                'mora' => $liquidacion['mora'],
                'descuento' => $descuento,
                'motivo_descuento' => $motivoDescuento,
                'vuelto' => bcsub($montoPagado, $montoCalculado, 2),
            ]);

            if ($esDiario) {
                $modeloGarantia = $this->tipos->paraCredito($credito)->garantiaModelo();
                $modeloGarantia::query()->whereIn('id', $this->garantiasDe($credito)->get()->pluck('id'))->update(['estado' => 'recuperado']);
                $this->documentos->generarCartaNoAdeudo($credito, $actor);
            } else {
                $this->documentos->generarDevolucion($credito, $actor);
            }

            $this->documentos->generarVoucherPago($credito, $actor, [
                'cobro_id' => $cobro->id,
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

            if ($esDiario) {
                $this->notificaciones->enviar(collect([$credito->registradoPor]), new CreditoLiquidadoNotification($credito));
            }

            return $this->conCobro($credito->fresh(['bienes', 'documentos']), $cobro);
        });
    }

    /**
     * Deshace un cobro (refrendo, adenda, liquidación, pago de cuota o
     * refinanciamiento) registrado por error — mismo criterio que
     * BovedaService::eliminarInyeccion(): solo mientras el ciclo de caja
     * donde se cobró sigue siendo el ciclo ABIERTO del actor (nunca uno ya
     * cerrado). administrador_general se salta esta restricción (mismo
     * criterio que cajas.cerrar_forzado): puede anular un cobro de cualquier
     * fecha, con el ciclo de caja donde se registró ya cerrado. Si la
     * operación generó un crédito sucesor (todo salvo liquidar()/la última
     * cuota de un compuesto), ese sucesor se borra entero — a menos que ya
     * se haya movido más allá de lo que este cobro dejó (tiene sus propios
     * cobros, o ya se desembolsó si nació "pendiente"), en cuyo caso se
     * rechaza la anulación. El crédito ORIGINAL vuelve exactamente al
     * estado que tenía antes (activo/vencido, capturado en
     * `credito_estado_anterior` al momento del cobro). El movimiento de
     * caja se borra (el saldo de su ciclo se recalcula solo, ver
     * CajaCiclo::saldoActual(), sea el ciclo abierto o uno ya cerrado); el
     * Cobro no se borra, queda marcado "anulado" para la auditoría.
     */
    public function anularCobro(Cobro $cobro, User $actor, ?string $motivo = null): Credito
    {
        if ($cobro->estado === 'anulado') {
            throw new DomainException('Este cobro ya está anulado.');
        }

        if (! $actor->hasRole('administrador_general')) {
            $ciclo = Caja::query()->where('user_id', $actor->id)->first()?->cicloAbierto()->first();

            if (! $ciclo || $cobro->caja_ciclo_id !== $ciclo->id) {
                throw new DomainException('Solo puedes anular un cobro mientras el ciclo de caja donde se registró sigue abierto.');
            }
        }

        $credito = $cobro->credito;

        return DB::transaction(function () use ($cobro, $credito, $actor, $motivo): Credito {
            if ($cobro->operacion === 'pago_cuotas_diario') {
                $this->deshacerPagoCuotas($cobro, $credito);
            } elseif ($cobro->credito_sucesor_id) {
                $this->deshacerSucesorDeCobro($cobro, $credito);
            } else {
                $this->deshacerLiquidacionDeCobro($credito);
            }

            $credito->update(['estado' => $cobro->credito_estado_anterior]);

            if ($cobro->caja_movimiento_id) {
                $movimiento = CajaMovimiento::query()->find($cobro->caja_movimiento_id);

                // Anular quita un ingreso de la caja: si el dinero ya se gastó o
                // desembolsó, el saldo quedaría negativo.
                if ($movimiento && $movimiento->tipo !== 'egreso' && $cobro->caja_ciclo_id) {
                    $cicloCobro = CajaCiclo::query()->whereKey($cobro->caja_ciclo_id)->lockForUpdate()->first();

                    if ($cicloCobro && bccomp(bcsub($cicloCobro->saldoActual(), (string) $movimiento->monto, 2), '0', 2) < 0) {
                        throw new DomainException('No puedes anular este cobro: la caja no tiene saldo suficiente para revertirlo (ya se usó ese dinero).');
                    }
                }

                $movimiento?->delete();
            }

            $cobro->update([
                'estado' => 'anulado',
                'anulado_por' => $actor->id,
                'anulado_at' => now(),
                'motivo_anulacion' => $motivo,
            ]);

            $credito = $credito->fresh();
            $this->notificar($credito);

            if ($cobro->caja_ciclo_id) {
                $ciclo = CajaCiclo::query()->find($cobro->caja_ciclo_id);
                CajaActualizada::dispatch($ciclo->caja, $ciclo->fresh()->saldoActual());
            }

            return $credito;
        });
    }

    /**
     * Borra el crédito sucesor que dejó un refrendo/adenda/pago de cuota/
     * refinanciamiento — mismo criterio de limpieza que eliminar(): quita
     * el vínculo de garantía, borra documentos (con cualquier escaneo
     * firmado) y cuotas, y por último el crédito mismo. El voucher_pago de
     * esa operación queda en el crédito ORIGINAL (generarVoucherPago() lo
     * asocia ahí, no al sucesor), así que se borra aparte.
     */
    private function deshacerSucesorDeCobro(Cobro $cobro, Credito $credito): void
    {
        $sucesor = Credito::query()->findOrFail($cobro->credito_sucesor_id);

        if ($sucesor->cobros()->where('estado', 'registrado')->exists()) {
            throw new DomainException('No se puede anular: ya se registró un cobro sobre el crédito sucesor.');
        }

        // adenda/refinanciamiento nacen "pendiente" y solo generan cuotas al
        // desembolsarse — si eso ya pasó, ese desembolso es una operación
        // aparte (sin su propio Cobro) que el chequeo de arriba no detecta.
        if (in_array($cobro->operacion, ['adenda', 'refinanciamiento'], true) && $sucesor->cuotas()->exists()) {
            throw new DomainException('No se puede anular: el crédito sucesor ya fue desembolsado.');
        }

        $this->garantiasDe($sucesor)->detach();

        foreach ($sucesor->documentos as $documento) {
            if ($documento->archivo_firmado_path) {
                Storage::disk('public')->delete($documento->archivo_firmado_path);
            }

            $documento->delete();
        }

        $sucesor->cuotas()->delete();
        $sucesor->delete();

        $credito->documentos()->where('tipo', 'voucher_pago')->latest()->first()?->delete();
    }

    /**
     * Deshace una liquidación (o el pago de la última cuota de un
     * compuesto, que termina igual sin sucesor): solo procede si el
     * crédito sigue "liquidado_pendiente" — si la devolución ya se firmó
     * (confirmarLiquidacionSiCorresponde() ya corrió), la garantía quedó
     * "recuperada" y se notificó al cliente, ya no es un simple error de
     * caja para deshacer aquí.
     */
    private function deshacerLiquidacionDeCobro(Credito $credito): void
    {
        if ($credito->estado !== 'liquidado_pendiente') {
            throw new DomainException('No se puede anular: la devolución de este crédito ya fue confirmada.');
        }

        $credito->documentos()->whereIn('tipo', ['devolucion', 'voucher_pago'])->get()->each(function (DocumentoCredito $documento): void {
            if ($documento->archivo_firmado_path) {
                Storage::disk('public')->delete($documento->archivo_firmado_path);
            }

            $documento->delete();
        });
    }

    /**
     * Deshace un pago de cuotas de un crédito diario (operación
     * 'pago_cuotas_diario') — a diferencia de refrendo/adenda/pago de cuota
     * compuesto, no hay sucesor que borrar: se revierten las CuotaCredito
     * que este cobro dejó marcadas como pagadas. Rechaza la anulación si ya
     * se pagó una cuota POSTERIOR con otro cobro más reciente — anular
     * dejaría un hueco en el cronograma, que las cuotas de un diario nunca
     * deben tener (siempre se pagan consecutivas desde la más antigua). Si
     * este cobro pagó la última cuota, el crédito ya saltó directo a
     * 'liquidado' (sin 'liquidado_pendiente' de por medio, ver
     * pagarCuotas()) — igual que a los demás tipos una vez liquidados,
     * ya no se puede deshacer.
     */
    private function deshacerPagoCuotas(Cobro $cobro, Credito $credito): void
    {
        $abonos = $cobro->abonosCuotas()->with('cuota')->get();

        if ($abonos->isNotEmpty()) {
            $this->deshacerAbonosCuotas($cobro, $credito, $abonos);

            return;
        }

        $maximoPagadoPorEsteCobro = (int) ($cobro->cuotasPagadas()->max('numero_cuota') ?? 0);

        $hayCuotaPosteriorPagada = $credito->cuotas()
            ->pagadas()
            ->where('numero_cuota', '>', $maximoPagadoPorEsteCobro)
            ->exists();

        if ($hayCuotaPosteriorPagada) {
            throw new DomainException('No se puede anular: ya se pagó una cuota posterior con otro cobro.');
        }

        if ($credito->estado === 'liquidado') {
            throw new DomainException('No se puede anular: este crédito ya quedó liquidado (carta de no adeudo generada).');
        }

        $credito->cuotas()->where('cobro_id', $cobro->id)->update([
            'pagada_at' => null,
            'mora_pagada' => null,
            'cobro_id' => null,
        ]);
    }

    /**
     * Revierte un cobro de diario registrado con detalle por cuota
     * (CobroCuotaAbono): resta lo abonado y la mora saldada a cada cuota y
     * reabre las que este cobro completó. Se rechaza si un cobro posterior
     * ya tocó esa cuota o una más nueva — anular dejaría el cronograma con
     * un hueco.
     *
     * @param  Collection<int, CobroCuotaAbono>  $abonos
     */
    private function deshacerAbonosCuotas(Cobro $cobro, Credito $credito, Collection $abonos): void
    {
        if ($credito->estado === 'liquidado') {
            throw new DomainException('No se puede anular: este crédito ya quedó liquidado (carta de no adeudo generada).');
        }

        $primeraCuotaTocada = (int) $abonos->min(fn (CobroCuotaAbono $abono): int => $abono->cuota->numero_cuota);

        $hayCobroPosterior = CobroCuotaAbono::query()
            ->where('cobro_id', '>', $cobro->id)
            ->whereHas('cuota', fn ($query) => $query->where('credito_id', $credito->id)->where('numero_cuota', '>=', $primeraCuotaTocada))
            ->exists();

        if ($hayCobroPosterior) {
            throw new DomainException('No se puede anular: ya se pagó una cuota posterior con otro cobro.');
        }

        foreach ($abonos as $abono) {
            $cuota = $abono->cuota;
            $moraRestante = bcsub((string) ($cuota->mora_pagada ?? 0), (string) $abono->monto_mora, 2);

            $cuota->update([
                'monto_abonado' => bcsub((string) $cuota->monto_abonado, (string) $abono->monto_cuota, 2),
                'mora_pagada' => bccomp($moraRestante, '0', 2) > 0 ? $moraRestante : null,
                'pagada_at' => $abono->completa ? null : $cuota->pagada_at,
                'cobro_id' => $abono->completa ? null : $cuota->cobro_id,
            ]);
        }

        $cobro->abonosCuotas()->delete();
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
     * Capital e interés de las cuotas ya pagadas dentro del crédito, y lo
     * abonado parcialmente a las que siguen pendientes — todo en 0 mientras
     * no se haya pagado por cuotas (los compuestos pagan por sucesor y nunca
     * tienen cuotas pagadas aquí).
     *
     * @return array{capital: string, interes: string, abonos: string}
     */
    private function cubiertoPorCuotasPagadas(Credito $credito): array
    {
        $pagadas = $credito->cuotas()->pagadas();

        return [
            'capital' => bcadd((string) $pagadas->sum('monto_capital'), '0', 2),
            'interes' => bcadd((string) $pagadas->sum('monto_interes'), '0', 2),
            'abonos' => bcadd((string) $credito->cuotas()->pendientes()->sum('monto_abonado'), '0', 2),
        ];
    }

    /**
     * @return array{capital: string, interes: string, abonos_cuotas: string, mora: string, dias_mora: int, total: string, dias_transcurridos: int, dias_minimo: int, dias_cobrados: int, tasa_interes: string}
     */
    public function calcularMontoLiquidacion(Credito $credito): array
    {
        $prorateo = $this->calcularInteresProrateado($credito);
        // Diario acumula mora por cuota individual desde que CADA una vence
        // (ver moraDeCuota()), no solo cuando el plazo completo del crédito
        // vence — calcularMora() (mora "a nivel crédito") subestimaría la
        // mora real de un diario con cuotas intermedias ya atrasadas.
        $mora = $credito->tipo_credito === 'diario' ? $this->moraPendienteDiario($credito) : $this->calcularMora($credito);

        // Lo que ya cubrieron las cuotas pagadas dentro del mismo crédito
        // (pago por cuotas) no se vuelve a cobrar: se descuenta el capital y
        // el interés de esas cuotas, y los abonos parciales a las pendientes.
        $cubierto = $this->cubiertoPorCuotasPagadas($credito);
        $capital = bcsub((string) $credito->monto_prestamo, $cubierto['capital'], 2);
        $interesRestante = bcsub($prorateo['interes'], $cubierto['interes'], 2);
        $interes = bccomp($interesRestante, '0', 2) > 0 ? $interesRestante : '0.00';
        $total = bcsub(bcadd(bcadd($capital, $interes, 2), $mora, 2), $cubierto['abonos'], 2);

        return [
            'capital' => $capital,
            'interes' => $interes,
            'abonos_cuotas' => $cubierto['abonos'],
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
     * Monto a pagar en pagarCuota(): la cuota fija en curso (del cronograma
     * ya generado) más la mora si está vencida — equivalente de
     * calcularMontoRefrendo() para un crédito de interés compuesto.
     *
     * @return array{numero_cuota: int, monto_capital: string, monto_interes: string, cuota_total: string, mora: string, total: string}
     */
    public function calcularMontoPagoCuota(Credito $credito): array
    {
        $cuota = $credito->cuotas()->orderBy('numero_cuota')->firstOrFail();
        $mora = $this->calcularMora($credito);

        return [
            'numero_cuota' => $cuota->numero_cuota,
            'monto_capital' => (string) $cuota->monto_capital,
            'monto_interes' => (string) $cuota->monto_interes,
            'cuota_total' => (string) $cuota->monto_total,
            'mora' => $mora,
            'total' => bcadd((string) $cuota->monto_total, $mora, 2),
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
                if (! $this->tipos->paraCredito($credito)->pasaATiendaAlVencer()) {
                    return;
                }

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

        if (! $tipo->pasaATiendaAlVencer()) {
            throw new DomainException('Este tipo de crédito no se envía a la tienda: no tiene garantía que rematar.');
        }

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

        if (! $this->tipos->paraCredito($credito)->pasaATiendaAlVencer()) {
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

    /**
     * Cierra el ciclo de vida de un crédito vehicular en_venta: registra al
     * comprador del vehículo ya ejecutado y genera el "Contrato de
     * Transferencia de Vehículo por Ejecución de Garantía". Solo aplica a
     * vehicular (confirmado explícitamente con el usuario) y solo mientras
     * el crédito está en_venta. Los pagos del comprador (depósitos previos +
     * saldo) NO mueven caja — se reciben por depósito bancario directo, fuera
     * del sistema (confirmado explícitamente); el documento solo deja
     * constancia de los montos y fechas declarados.
     *
     * @param  array{comprador_nombre: string, comprador_tipo_documento: string, comprador_numero_documento: string, comprador_domicilio?: string|null, precio_transferencia: string, pagos_previos?: list<array{monto: string, fecha: string}>}  $datosComprador
     */
    public function vender(Credito $credito, User $actor, array $datosComprador): Credito
    {
        if ($credito->tipo_credito !== 'vehicular') {
            throw new DomainException('Solo los créditos vehiculares pueden cerrarse con un contrato de transferencia.');
        }

        $this->asegurarEstado($credito, 'en_venta');

        return DB::transaction(function () use ($credito, $actor, $datosComprador): Credito {
            $credito->update(['estado' => 'vendido']);

            $this->documentos->generarContratoTransferencia($credito, $actor, $datosComprador);

            $credito = $credito->fresh();
            $this->notificar($credito);
            $this->notificaciones->enviar(
                $this->hierarchy->controladoresDe($credito)->push($credito->registradoPor),
                new CreditoVendidoNotification($credito),
            );

            return $credito;
        });
    }

    public function calcularMora(Credito $credito): string
    {
        $dias = $credito->dias_en_mora;

        if ($dias === 0) {
            return '0.00';
        }

        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->tipo_credito);
        $tasaDiaria = bcdiv((string) $configuracion->tasa_mora_diaria, '100', 4);

        // Compuesto: la mora penaliza solo la cuota vencida impaga (el
        // monto que efectivamente no se pagó a tiempo), no el saldo insoluto
        // completo como en el modelo simple — confirmado con el usuario.
        if ($credito->tipo_interes === 'compuesto') {
            $cuota = $credito->cuotas()->orderBy('numero_cuota')->first();

            if (! $cuota) {
                return '0.00';
            }

            return bcmul(bcmul((string) $cuota->monto_total, $tasaDiaria, 4), (string) $dias, 2);
        }

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
     * @param  array{operacion: string, credito_estado_anterior: string, interes: string, mora?: string|null, descuento?: string|null, motivo_descuento?: string|null, vuelto?: string, credito_sucesor_id?: int|null}  $detalleCobro
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
    ): Cobro {
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

        $cobro = Cobro::query()->create([
            'empresa_id' => $credito->empresa_id,
            'cliente_id' => $credito->cliente_id,
            'credito_id' => $credito->id,
            'credito_sucesor_id' => $detalleCobro['credito_sucesor_id'] ?? null,
            'caja_ciclo_id' => $ciclo->id,
            'caja_movimiento_id' => $movimiento->id,
            'registrado_por' => $actor->id,
            'operacion' => $detalleCobro['operacion'],
            'credito_estado_anterior' => $detalleCobro['credito_estado_anterior'],
            'monto_pagado' => $monto,
            'medio' => $medio,
            'interes' => $detalleCobro['interes'],
            'mora' => $detalleCobro['mora'] ?? null,
            'descuento' => $detalleCobro['descuento'] ?? null,
            'motivo_descuento' => $detalleCobro['motivo_descuento'] ?? null,
            'vuelto' => $detalleCobro['vuelto'] ?? '0.00',
        ]);

        CajaActualizada::dispatch($ciclo->caja, $ciclo->fresh()->saldoActual());

        return $cobro;
    }

    /**
     * Variante de registrarCobroEnCaja() para refinanciar(): `$monto` puede
     * ser legítimamente cero (toda la deuda se traslada al nuevo capital,
     * sin que cambie de manos dinero real) — en ese caso no exige caja
     * aperturada ni genera movimiento de caja, solo deja la fila de
     * auditoría en `cobros` (caja_ciclo_id queda null, columna ya nullable).
     *
     * @param  array{interes: string, credito_estado_anterior: string, mora?: string|null, descuento?: string|null, motivo_descuento?: string|null, credito_sucesor_id?: int|null}  $detalleCobro
     */
    private function registrarCobroRefinanciamiento(
        User $actor,
        Credito $credito,
        string $monto,
        string $medio,
        ?UploadedFile $comprobante,
        array $detalleCobro,
    ): Cobro {
        $ciclo = null;
        $movimiento = null;

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

        $cobro = Cobro::query()->create([
            'empresa_id' => $credito->empresa_id,
            'cliente_id' => $credito->cliente_id,
            'credito_id' => $credito->id,
            'credito_sucesor_id' => $detalleCobro['credito_sucesor_id'] ?? null,
            'caja_ciclo_id' => $ciclo?->id,
            'caja_movimiento_id' => $movimiento?->id,
            'registrado_por' => $actor->id,
            'operacion' => 'refinanciamiento',
            'credito_estado_anterior' => $detalleCobro['credito_estado_anterior'],
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

        return $cobro;
    }

    /**
     * Deja el id del cobro en el crédito que devuelve cada operación de
     * pago, para que el cliente abra su voucher (GET /cobros/{id}/voucher)
     * apenas termina de cobrar.
     */
    private function conCobro(Credito $credito, Cobro $cobro): Credito
    {
        $credito->setAttribute('cobro_id', $cobro->id);

        return $credito;
    }
}
