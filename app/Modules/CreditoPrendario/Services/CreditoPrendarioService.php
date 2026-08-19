<?php

namespace App\Modules\CreditoPrendario\Services;

use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CreditoPrendarioService
{
    public function __construct(
        private readonly ConfiguracionCreditoPrendarioService $configuracion,
        private readonly DocumentoCreditoPrendarioService $documentos,
    ) {}

    /**
     * @param  Collection<int, Bien>  $bienes
     * @param  array{monto_prestamo: string, interes?: string, tipo_cuota: string}  $datos
     */
    public function registrar(User $actor, Collection $bienes, array $datos): CreditoPrendario
    {
        if ($bienes->isEmpty()) {
            throw new DomainException('Debes seleccionar al menos un bien.');
        }

        if ($bienes->pluck('cliente_id')->unique()->count() > 1) {
            throw new DomainException('Todos los bienes deben pertenecer al mismo cliente.');
        }

        if ($bienes->pluck('tipo')->unique()->count() > 1) {
            throw new DomainException('Todos los bienes de un crédito deben ser del mismo tipo (Electro o Varios).');
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
        $configuracion = $this->configuracion->resolverPara($primerBien->agencia, $primerBien->tipo);

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

            return $credito->fresh(['bienes']);
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

            return $credito->fresh();
        });
    }

    public function rechazar(CreditoPrendario $credito, User $aprobador, ?string $motivo = null): CreditoPrendario
    {
        $this->asegurarEstado($credito, 'pendiente');

        $credito->update([
            'estado' => 'rechazado',
            'aprobado_por' => $aprobador->id,
            'motivo_rechazo' => $motivo,
            'fecha_aprobacion' => now(),
        ]);

        return $credito->fresh();
    }

    public function firmar(CreditoPrendario $credito, User $actor): CreditoPrendario
    {
        $this->asegurarEstado($credito, 'aprobado');

        return DB::transaction(function () use ($credito): CreditoPrendario {
            $fechaDesembolso = now()->startOfDay();

            $credito->update([
                'estado' => 'activo',
                'fecha_desembolso' => $fechaDesembolso->toDateString(),
                'fecha_vencimiento' => $fechaDesembolso->copy()->addDays($credito->plazo_dias)->toDateString(),
            ]);

            $credito->documentos()->whereNull('firmado_at')->get()
                ->each(fn ($documento) => $this->documentos->marcarFirmado($documento));

            return $credito->fresh();
        });
    }

    public function refrendar(CreditoPrendario $credito, User $actor, string $montoInteresPagado): CreditoPrendario
    {
        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede refrendar un crédito activo o vencido.');
        }

        if (bccomp($montoInteresPagado, '0', 2) <= 0) {
            throw new DomainException('El monto de interés pagado debe ser mayor a cero.');
        }

        $primerBien = $credito->bienes->first();
        $configuracion = $this->configuracion->resolverPara($credito->agencia, $primerBien->tipo);
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

            return $nuevo->fresh(['bienes']);
        });
    }

    public function liquidar(CreditoPrendario $credito, User $actor): CreditoPrendario
    {
        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede liquidar un crédito activo o vencido.');
        }

        return DB::transaction(function () use ($credito): CreditoPrendario {
            $credito->update(['estado' => 'liquidado']);
            Bien::query()->whereIn('id', $credito->bienes->pluck('id'))->update(['estado' => 'recuperado']);

            return $credito->fresh(['bienes']);
        });
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
                $primerBien = $credito->bienes->first();
                $configuracion = $this->configuracion->resolverPara($credito->agencia, $primerBien->tipo);
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

        $primerBien = $credito->bienes->first();
        $configuracion = $this->configuracion->resolverPara($credito->agencia, $primerBien->tipo);
        $tasaDiaria = bcdiv((string) $configuracion->tasa_mora_diaria, '100', 4);

        return bcmul(bcmul((string) $credito->monto_prestamo, $tasaDiaria, 4), (string) $dias, 2);
    }

    private function asegurarEstado(CreditoPrendario $credito, string $esperado): void
    {
        if ($credito->estado !== $esperado) {
            throw new DomainException("El crédito debe estar en estado '{$esperado}' para esta acción (actual: '{$credito->estado}').");
        }
    }
}
