<?php

namespace App\Modules\Caja\Models;

use App\Modules\Credito\Models\Credito;
use App\Modules\Sistemas\Models\Concepto;
use App\Modules\Usuario\Models\User;
use App\Modules\Venta\Models\Venta;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CajaMovimientoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CajaMovimiento extends Model
{
    /** @use HasFactory<CajaMovimientoFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'caja_ciclo_id',
        'empresa_id',
        'tipo',
        'monto',
        'medio',
        'canal',
        'concepto',
        'descripcion',
        'serie',
        'numero',
        'concepto_id',
        'billetaje_id',
        'venta_id',
        'credito_id',
        'registrado_por',
        'fecha_caja',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_caja' => 'date',
        ];
    }

    /**
     * Desembolsos reales: el movimiento de caja conserva el crédito que lo
     * originó. No se usa la ausencia de concepto porque un egreso manual
     * siempre lleva concepto y los registros históricos pueden ser inválidos.
     */
    public function scopeDesembolsos(Builder $query): Builder
    {
        return $query->where('tipo', 'egreso')->whereNotNull('credito_id');
    }

    /** Egresos que no provienen de un desembolso de crédito. */
    public function scopeEgresosManuales(Builder $query): Builder
    {
        return $query->where('tipo', 'egreso')->whereNull('credito_id');
    }

    /**
     * Ingresos manuales: los que registra el propio módulo Ingresos
     * (CajaService::registrarMovimiento(), que siempre exige un concepto del
     * catálogo). Un cobro o un pago de venta TAMBIÉN generan un movimiento
     * tipo=ingreso (ver CreditoService::registrarCobroEnCaja() y
     * VentaService::registrarPago()), pero ninguno de los dos lleva
     * concepto_id — por eso ese campo (y no credito_id/venta_id, que acá
     * apuntarían al origen, no a "es manual") es lo que distingue uno de
     * otro, sin contar dos veces el mismo dinero que ya suma `cobranza`.
     */
    public function scopeIngresosManuales(Builder $query): Builder
    {
        return $query->where('tipo', 'ingreso')->whereNotNull('concepto_id');
    }

    /**
     * Billetaje aprobado (efectivo o digital — ver BilletajeService): dinero
     * que entra a esta caja desde la bóveda, igual de real que un ingreso
     * para CajaCiclo::saldoActual(), pero de origen interno, no de un
     * cliente. `registrado_por` acá es quien APROBÓ el billetaje (el
     * administrador), no el asesor que lo recibió — para saber de quién es
     * hay que mirar la caja (cajaCiclo.caja.user_id), no este campo.
     */
    public function scopeBilletajes(Builder $query): Builder
    {
        return $query->where('tipo', 'billetaje');
    }

    public function cajaCiclo(): BelongsTo
    {
        return $this->belongsTo(CajaCiclo::class);
    }

    public function billetaje(): BelongsTo
    {
        return $this->belongsTo(Billetaje::class);
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(Concepto::class);
    }

    /**
     * Solo se setea en un egreso de desembolso (ver CreditoService::desembolsar()).
     */
    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class);
    }

    /**
     * Solo se setea en un ingreso de una venta de tienda (ver VentaService::registrarPago()).
     */
    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function fotos(): MorphMany
    {
        return $this->morphMany(MovimientoFoto::class, 'fotografiable');
    }

    protected static function newFactory(): CajaMovimientoFactory
    {
        return CajaMovimientoFactory::new();
    }
}
