<?php

namespace App\Modules\CreditoPrendario\Services;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\Usuario\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CreditoPrendarioService
{
    public function __construct(
        private readonly ConfiguracionCreditoPrendarioService $configuracion,
        private readonly DocumentoCreditoPrendarioService $documentos,
    ) {}

    /**
     * @param  array{monto_prestamo: string, interes?: string, tipo_cuota: string}  $datos
     */
    public function registrar(User $actor, Bien $bien, Cliente $cliente, array $datos): CreditoPrendario
    {
        $configuracion = $this->configuracion->resolverPara($bien->agencia, $bien->tipo);

        return DB::transaction(fn (): CreditoPrendario => CreditoPrendario::query()->create([
            'empresa_id' => $bien->empresa_id,
            'agencia_id' => $bien->agencia_id,
            'bien_id' => $bien->id,
            'cliente_id' => $cliente->id,
            'registrado_por' => $actor->id,
            'numero_refrendo' => 0,
            'monto_prestamo' => $datos['monto_prestamo'],
            'interes' => $datos['interes'] ?? $configuracion->interes_default,
            'tipo_cuota' => $datos['tipo_cuota'],
            'plazo_dias' => $configuracion->plazo_dias,
            'estado' => 'pendiente',
        ]));
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

        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->bien->tipo);
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
                'bien_id' => $credito->bien_id,
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

            $this->documentos->generarAdenda($nuevo, $actor);

            return $nuevo;
        });
    }

    public function liquidar(CreditoPrendario $credito, User $actor): CreditoPrendario
    {
        if (! in_array($credito->estado, ['activo', 'vencido'], true)) {
            throw new DomainException('Solo se puede liquidar un crédito activo o vencido.');
        }

        return DB::transaction(function () use ($credito): CreditoPrendario {
            $credito->update(['estado' => 'liquidado']);
            $credito->bien->update(['estado' => 'recuperado']);

            return $credito->fresh();
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
            ->with(['bien', 'agencia'])
            ->get()
            ->each(function (CreditoPrendario $credito) use ($hoy): void {
                $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->bien->tipo);
                $fechaLimite = $credito->fecha_vencimiento->copy()->addDays($configuracion->dias_espera_mora)->toDateString();

                if ($fechaLimite < $hoy) {
                    $credito->update(['estado' => 'en_venta']);
                    $credito->bien->update(['estado' => 'disponible_venta']);
                }
            });
    }

    public function calcularMora(CreditoPrendario $credito): string
    {
        $dias = $credito->dias_en_mora;

        if ($dias === 0) {
            return '0.00';
        }

        $configuracion = $this->configuracion->resolverPara($credito->agencia, $credito->bien->tipo);
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
