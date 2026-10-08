<?php

namespace App\Modules\Planilla\Models;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\ConfiguracionPlanillaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tasas de aportes con las que se calcula cada línea de planilla, por
 * empresa/agencia (agencia_id nulo = fila default de la empresa). El módulo
 * empieza con las tasas por defecto del régimen peruano; se editan desde
 * Sistemas para empresas con otro régimen. Ver ConfiguracionPlanillaService.
 */
class ConfiguracionPlanilla extends Model
{
    /** @use HasFactory<ConfiguracionPlanillaFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'configuraciones_planilla';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'onp_trabajador',
        'afp_trabajador',
        'essalud_trabajador',
        'essalud_empleador',
        'cts_empleador',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'onp_trabajador' => 'decimal:2',
            'afp_trabajador' => 'decimal:2',
            'essalud_trabajador' => 'decimal:2',
            'essalud_empleador' => 'decimal:2',
            'cts_empleador' => 'decimal:2',
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

    protected static function newFactory(): ConfiguracionPlanillaFactory
    {
        return ConfiguracionPlanillaFactory::new();
    }
}
