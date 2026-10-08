<?php

namespace App\Modules\Planilla\Models;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Planilla\Tipos\DescuentoTipo;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\PlanillaDescuentoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adelanto, préstamo o descuento lanzado contra el sueldo de un trabajador.
 * Vive fuera de la planilla: se registra una vez y cada mes que se confirma la
 * planilla lo consume (`pagado`) hasta saldar `saldo`.
 *
 * `cuota` es lo que se descuenta POR MES: nulo = todo el saldo de una vez (un
 * adelanto), con valor = en cuotas (un préstamo, una falta).
 */
class PlanillaDescuento extends Model
{
    /** @use HasFactory<PlanillaDescuentoFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'user_id',
        'tipo',
        'concepto',
        'monto',
        'cuota',
        'pagado',
        'saldo',
        'fecha_registro',
        'estado',
        'registrado_por',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'cuota' => 'decimal:2',
            'pagado' => 'decimal:2',
            'saldo' => 'decimal:2',
            'fecha_registro' => 'date',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function tipoEnum(): ?DescuentoTipo
    {
        return DescuentoTipo::tryFrom($this->tipo);
    }

    /**
     * Cuánto se le descontaría a este trabajador en el próximo mes: la cuota
     * mensual topada por lo que saldo queda (una cuota de 100 sobre un saldo
     * de 30 descuenta 30 y lo salda).
     */
    public function cuotaDelMes(): string
    {
        $cuota = (string) ($this->cuota ?? $this->saldo);

        // Se compara como float solo para elegir el menor — ambos vienen de
        // decimal(12,2), donde no hay riesgo de precisión. La resta del saldo
        // se hace aparte con bcmath.
        return (float) $cuota <= (float) $this->saldo ? $cuota : $this->saldo;
    }

    protected static function newFactory(): PlanillaDescuentoFactory
    {
        return PlanillaDescuentoFactory::new();
    }
}
