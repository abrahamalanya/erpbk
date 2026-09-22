<?php

namespace App\Modules\Venta\Services;

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Sistemas\Services\NotificacionService;
use App\Modules\Tienda\Services\TiendaService;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\CuotaVenta;
use App\Modules\Venta\Models\PagoVenta;
use App\Modules\Venta\Models\Venta;
use App\Modules\Venta\Notifications\VentaApartadoVencidaNotification;
use App\Modules\Venta\Notifications\VentaRegistradaNotification;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Motor de venta de artículos de tienda (bien/vehículo/inmueble en estado
 * disponible_venta) a un cliente comprador, en una de tres modalidades:
 * contado (pago único, queda pagada de inmediato), crédito (inicial +
 * cronograma con interés configurable) o apartado (inicial + abonos libres
 * hasta una fecha límite). Independiente del motor de Credito — ver el
 * Contexto del plan en curso.
 */
final class VentaService
{
    public function __construct(
        private readonly TiendaService $tienda,
        private readonly ConfiguracionVentaService $configuracion,
        private readonly DocumentoVentaService $documentos,
        private readonly NotificacionService $notificaciones,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(User $actor, Cliente $cliente, Model $articulo, array $datos): Venta
    {
        if ($articulo->estado !== 'disponible_venta') {
            throw new DomainException('Este artículo ya no está disponible en la tienda.');
        }

        $ciclo = $this->cicloAbiertoDe($actor);
        $precio = $this->precioEfectivo($articulo);

        return match ($datos['forma_venta']) {
            'contado' => DB::transaction(fn (): Venta => $this->crearContado($actor, $cliente, $articulo, $ciclo, $precio, $datos)),
            'credito' => DB::transaction(fn (): Venta => $this->crearCredito($actor, $cliente, $articulo, $ciclo, $precio, $datos)),
            'apartado' => DB::transaction(fn (): Venta => $this->crearApartado($actor, $cliente, $articulo, $ciclo, $precio, $datos)),
            default => throw new DomainException("Forma de venta inválida: {$datos['forma_venta']}"),
        };
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crearContado(User $actor, Cliente $cliente, Model $articulo, CajaCiclo $ciclo, string $precio, array $datos): Venta
    {
        $venta = $this->crearFila($actor, $cliente, $articulo, [
            'forma_venta' => 'contado',
            'estado' => 'activa',
            'precio_venta' => $precio,
            'inicial' => $precio,
            'saldo_pendiente' => 0,
        ]);

        $this->registrarPago($venta, $ciclo, $actor, 'contado', $precio, $datos['medio'], null);
        $this->marcarPagada($venta->fresh());
        $this->notificarRegistro($venta->fresh(), $actor);

        return $venta->fresh(['pagos', 'documentos']);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crearCredito(User $actor, Cliente $cliente, Model $articulo, CajaCiclo $ciclo, string $precio, array $datos): Venta
    {
        $inicial = (string) $datos['inicial'];

        if (bccomp($inicial, $precio, 2) >= 0) {
            throw new DomainException('El inicial debe ser menor al precio de venta.');
        }

        $numeroCuotas = (int) $datos['numero_cuotas'];
        $interes = isset($datos['interes'])
            ? (string) $datos['interes']
            : (string) $this->configuracion->resolverPara($articulo->agencia)->interes_mensual_default;

        $montoFinanciar = bcsub($precio, $inicial, 2);
        $cronograma = $this->calcularCronograma($montoFinanciar, $interes, $numeroCuotas);

        $venta = $this->crearFila($actor, $cliente, $articulo, [
            'forma_venta' => 'credito',
            'estado' => 'activa',
            'precio_venta' => $precio,
            'inicial' => $inicial,
            'interes' => $interes,
            'numero_cuotas' => $numeroCuotas,
            'saldo_pendiente' => $montoFinanciar,
        ]);

        foreach ($cronograma as $fila) {
            CuotaVenta::query()->create([
                'venta_id' => $venta->id,
                'empresa_id' => $venta->empresa_id,
                'numero_cuota' => $fila['numero_cuota'],
                'fecha_vencimiento' => $fila['fecha_vencimiento'],
                'monto_capital' => $fila['monto_capital'],
                'monto_interes' => $fila['monto_interes'],
                'monto_total' => $fila['monto_total'],
            ]);
        }

        if (bccomp($inicial, '0', 2) > 0) {
            $this->registrarPago($venta, $ciclo, $actor, 'inicial', $inicial, $datos['medio'], null);
        }

        $articulo->update(['estado' => 'reservada']);
        $this->documentos->generarContratoInicial($venta, $actor);
        $this->notificarRegistro($venta->fresh(), $actor);

        return $venta->fresh(['cuotas', 'pagos', 'documentos']);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crearApartado(User $actor, Cliente $cliente, Model $articulo, CajaCiclo $ciclo, string $precio, array $datos): Venta
    {
        $inicial = (string) $datos['inicial'];

        if (bccomp($inicial, $precio, 2) >= 0) {
            throw new DomainException('El inicial debe ser menor al precio de venta.');
        }

        $venta = $this->crearFila($actor, $cliente, $articulo, [
            'forma_venta' => 'apartado',
            'estado' => 'activa',
            'precio_venta' => $precio,
            'inicial' => $inicial,
            'fecha_limite' => $datos['fecha_limite'],
            'saldo_pendiente' => bcsub($precio, $inicial, 2),
        ]);

        if (bccomp($inicial, '0', 2) > 0) {
            $this->registrarPago($venta, $ciclo, $actor, 'inicial', $inicial, $datos['medio'], null);
        }

        $articulo->update(['estado' => 'reservada']);
        $this->documentos->generarContratoInicial($venta, $actor);
        $this->notificarRegistro($venta->fresh(), $actor);

        return $venta->fresh(['pagos', 'documentos']);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function crearFila(User $actor, Cliente $cliente, Model $articulo, array $atributos): Venta
    {
        return Venta::query()->create([
            'empresa_id' => $articulo->empresa_id,
            'agencia_id' => $articulo->agencia_id,
            'articulo_type' => $articulo->getMorphClass(),
            'articulo_id' => $articulo->id,
            'credito_origen_id' => $articulo->creditos()->latest()->first()?->id,
            'cliente_id' => $cliente->id,
            'vendido_por' => $actor->id,
            ...$atributos,
        ]);
    }

    /**
     * Paga (total o parcialmente) una cuota de una venta a crédito. Marca la
     * venta como pagada cuando ya no queda ninguna cuota pendiente.
     */
    public function pagarCuota(Venta $venta, CuotaVenta $cuota, User $actor, string $monto, string $medio): Venta
    {
        if ($venta->forma_venta !== 'credito') {
            throw new DomainException('Solo una venta a crédito admite pago de cuotas.');
        }

        if ($venta->estado !== 'activa') {
            throw new DomainException("La venta debe estar activa para pagar cuotas (actual: '{$venta->estado}').");
        }

        if ($cuota->venta_id !== $venta->id) {
            throw new DomainException('Esta cuota no pertenece a esta venta.');
        }

        if ($cuota->estado === 'pagada') {
            throw new DomainException('Esta cuota ya está pagada.');
        }

        $pendiente = bcsub($cuota->monto_total, $cuota->monto_abonado, 2);

        if (bccomp($monto, $pendiente, 2) > 0) {
            throw new DomainException('El monto supera lo pendiente de esta cuota.');
        }

        $ciclo = $this->cicloAbiertoDe($actor);

        return DB::transaction(function () use ($venta, $cuota, $actor, $monto, $medio, $ciclo): Venta {
            $nuevoAbonado = bcadd($cuota->monto_abonado, $monto, 2);

            $cuota->update([
                'monto_abonado' => $nuevoAbonado,
                'estado' => bccomp($nuevoAbonado, $cuota->monto_total, 2) >= 0 ? 'pagada' : 'pendiente',
            ]);

            $this->registrarPago($venta, $ciclo, $actor, 'cuota', $monto, $medio, $cuota);

            $saldo = $venta->cuotas()->get()->reduce(
                fn (string $carry, CuotaVenta $c): string => bcadd($carry, bcsub($c->monto_total, $c->monto_abonado, 2), 2),
                '0'
            );

            $venta->update(['saldo_pendiente' => $saldo]);

            if (bccomp($saldo, '0', 2) <= 0) {
                $this->marcarPagada($venta->fresh());
            }

            return $venta->fresh(['cuotas', 'pagos', 'documentos']);
        });
    }

    /**
     * Registra un abono libre sobre el saldo de un apartado. Marca la venta
     * como pagada cuando el saldo llega a cero.
     */
    public function abonar(Venta $venta, User $actor, string $monto, string $medio): Venta
    {
        if ($venta->forma_venta !== 'apartado') {
            throw new DomainException('Solo un apartado admite abonos libres.');
        }

        if ($venta->estado !== 'activa') {
            throw new DomainException("La venta debe estar activa para abonar (actual: '{$venta->estado}').");
        }

        if (bccomp($monto, $venta->saldo_pendiente, 2) > 0) {
            throw new DomainException('El abono supera el saldo pendiente.');
        }

        $ciclo = $this->cicloAbiertoDe($actor);

        return DB::transaction(function () use ($venta, $actor, $monto, $medio, $ciclo): Venta {
            $this->registrarPago($venta, $ciclo, $actor, 'abono', $monto, $medio, null);

            $saldo = bcsub($venta->saldo_pendiente, $monto, 2);
            $venta->update(['saldo_pendiente' => $saldo]);

            if (bccomp($saldo, '0', 2) <= 0) {
                $this->marcarPagada($venta->fresh());
            }

            return $venta->fresh(['pagos', 'documentos']);
        });
    }

    /**
     * Cancela manualmente un apartado activo (misma consecuencia que el
     * vencimiento automático: se pierde el inicial y los abonos, el
     * artículo vuelve a la tienda).
     */
    public function cancelar(Venta $venta, User $actor): Venta
    {
        if ($venta->forma_venta !== 'apartado') {
            throw new DomainException('Solo un apartado puede cancelarse.');
        }

        if ($venta->estado !== 'activa') {
            throw new DomainException("La venta debe estar activa para cancelarse (actual: '{$venta->estado}').");
        }

        return DB::transaction(function () use ($venta): Venta {
            $this->cancelarInterna($venta);

            return $venta->fresh(['pagos']);
        });
    }

    /**
     * Cancela automáticamente todo apartado activo cuya fecha_limite ya
     * pasó sin cubrir el saldo — usado por el comando programado
     * ventas:cancelar-apartados-vencidos.
     */
    public function cancelarVencidas(): int
    {
        $vencidas = Venta::query()
            ->where('forma_venta', 'apartado')
            ->where('estado', 'activa')
            ->whereDate('fecha_limite', '<', now()->startOfDay())
            ->get();

        foreach ($vencidas as $venta) {
            DB::transaction(function () use ($venta): void {
                $this->cancelarInterna($venta, notificarVencimiento: true);
            });
        }

        return $vencidas->count();
    }

    private function cancelarInterna(Venta $venta, bool $notificarVencimiento = false): void
    {
        $venta->update(['estado' => 'cancelada', 'cancelada_at' => now()]);
        $venta->articulo->update(['estado' => 'disponible_venta']);

        $venta = $venta->fresh();

        if ($notificarVencimiento) {
            $this->notificaciones->enviar(
                $this->tienda->controladoresDe($venta->articulo)->push($venta->vendidoPor),
                new VentaApartadoVencidaNotification($venta),
            );
        }
    }

    /**
     * Marca la venta como pagada en su totalidad: pasa la garantía a
     * "vendida" (deja de listarse en la tienda) y genera el voucher más el
     * documento legal que corresponda según el tipo de artículo.
     */
    private function marcarPagada(Venta $venta): void
    {
        $venta->update(['estado' => 'pagada', 'pagada_at' => now(), 'saldo_pendiente' => 0]);
        $venta->articulo->update(['estado' => 'vendida']);
        $this->documentos->generarDocumentosFinales($venta->fresh());
    }

    private function notificarRegistro(Venta $venta, User $actor): void
    {
        $this->notificaciones->enviar(
            $this->tienda->controladoresDe($venta->articulo)->push($actor),
            new VentaRegistradaNotification($venta),
        );
    }

    private function registrarPago(Venta $venta, CajaCiclo $ciclo, User $actor, string $tipo, string $monto, string $medio, ?CuotaVenta $cuota): PagoVenta
    {
        $movimiento = CajaMovimiento::query()->create([
            'caja_ciclo_id' => $ciclo->id,
            'empresa_id' => $ciclo->empresa_id,
            'tipo' => 'ingreso',
            'monto' => $monto,
            'medio' => $medio,
            'concepto' => "Venta #{$venta->id} — ".ucfirst($tipo),
            'venta_id' => $venta->id,
            'registrado_por' => $actor->id,
            'fecha_caja' => $ciclo->fecha,
        ]);

        return PagoVenta::query()->create([
            'venta_id' => $venta->id,
            'cuota_venta_id' => $cuota?->id,
            'empresa_id' => $venta->empresa_id,
            'caja_ciclo_id' => $ciclo->id,
            'caja_movimiento_id' => $movimiento->id,
            'registrado_por' => $actor->id,
            'tipo' => $tipo,
            'monto' => $monto,
            'medio' => $medio,
        ]);
    }

    private function precioEfectivo(Model $articulo): string
    {
        return (string) ($articulo->precio_oferta ?? $articulo->precio_venta);
    }

    private function cicloAbiertoDe(User $actor): CajaCiclo
    {
        $caja = Caja::query()->where('user_id', $actor->id)->first();
        $ciclo = $caja?->cicloAbierto()->first();

        if (! $ciclo) {
            throw new DomainException('Debes aperturar tu caja antes de registrar una venta.');
        }

        return $ciclo;
    }

    /**
     * Interés simple mensual (cuotas de 30 días), monto fijo de capital por
     * cuota y una cuota de interés fija (monto * tasa/100), igual en cada
     * cuota — mismo criterio que CreditoService::filasCronograma() pero
     * simplificado a periodos mensuales fijos (Venta no tiene tipos de
     * cuota diario/semanal). tasa = 0 => cuotas sin interés.
     *
     * @return list<array{numero_cuota: int, fecha_vencimiento: string, monto_capital: string, monto_interes: string, monto_total: string}>
     */
    public function calcularCronograma(string $monto, string $tasa, int $n): array
    {
        if ($n < 1) {
            throw new DomainException('El número de cuotas debe ser al menos 1.');
        }

        $capitalPorCuota = bcdiv($monto, (string) $n, 2);
        $interesPorCuota = bcdiv(bcmul($monto, bcdiv($tasa, '100', 10), 10), '1', 2);
        $saldoCapital = $monto;
        $base = now()->startOfDay();
        $filas = [];

        for ($i = 1; $i <= $n; $i++) {
            $capitalCuota = $i === $n ? $saldoCapital : $capitalPorCuota;

            $filas[] = [
                'numero_cuota' => $i,
                'fecha_vencimiento' => $base->copy()->addDays(30 * $i)->toDateString(),
                'monto_capital' => $capitalCuota,
                'monto_interes' => $interesPorCuota,
                'monto_total' => bcadd($capitalCuota, $interesPorCuota, 2),
            ];

            $saldoCapital = bcsub($saldoCapital, $capitalCuota, 2);
        }

        return $filas;
    }
}
