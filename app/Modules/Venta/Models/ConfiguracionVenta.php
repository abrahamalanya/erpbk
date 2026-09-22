<?php

namespace App\Modules\Venta\Models;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\ConfiguracionVentaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tasa de interés por defecto para la venta a crédito, por empresa/agencia
 * (agencia_id nulo = fila default de la empresa). Mirror de
 * ConfiguracionCredito — ver ConfiguracionVentaService::resolverPara().
 */
class ConfiguracionVenta extends Model
{
    /** @use HasFactory<ConfiguracionVentaFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'configuraciones_venta';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'interes_mensual_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interes_mensual_default' => 'decimal:2',
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

    protected static function newFactory(): ConfiguracionVentaFactory
    {
        return ConfiguracionVentaFactory::new();
    }
}
