<?php

namespace App\Modules\CreditoPrendario\Models;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\ConfiguracionCreditoPrendarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfiguracionCreditoPrendario extends Model
{
    /** @use HasFactory<ConfiguracionCreditoPrendarioFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'configuraciones_credito_prendario';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'tipo',
        'interes_default',
        'plazo_dias',
        'dias_espera_mora',
        'tasa_mora_diaria',
        'max_refrendos',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'interes_default' => 'decimal:2',
            'tasa_mora_diaria' => 'decimal:2',
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

    protected static function newFactory(): ConfiguracionCreditoPrendarioFactory
    {
        return ConfiguracionCreditoPrendarioFactory::new();
    }
}
