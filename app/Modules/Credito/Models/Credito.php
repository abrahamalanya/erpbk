<?php

namespace App\Modules\Credito\Models;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CreditoFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Credito extends Model
{
    /** @use HasFactory<CreditoFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'creditos_prendarios';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'tipo_credito',
        'cliente_id',
        'registrado_por',
        'supervisado_por',
        'refrendo_de_credito_id',
        'numero_refrendo',
        'adenda_de_credito_id',
        'monto_prestamo',
        'interes',
        'tipo_cuota',
        'plazo_dias',
        'estado',
        'aprobado_por',
        'fecha_aprobacion',
        'motivo_rechazo',
        'fecha_desembolso',
        'fecha_vencimiento',
        'conformidad_path',
        'conformidad_confirmada_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monto_prestamo' => 'decimal:2',
            'interes' => 'decimal:2',
            'fecha_aprobacion' => 'datetime',
            'fecha_desembolso' => 'date',
            'fecha_vencimiento' => 'date',
            'conformidad_confirmada_at' => 'datetime',
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

    /**
     * The garantías of the given model backing this crédito. A crédito only
     * ever holds garantías of one kind (its tipo_credito's), so the engine
     * resolves the right model from the CreditoTipo strategy and calls this.
     *
     * @param  class-string<Model>  $modelo
     */
    public function garantiasComo(string $modelo): MorphToMany
    {
        return $this->morphedByMany($modelo, 'garantia', 'credito_garantia', 'credito_id', 'garantia_id')
            ->withTimestamps();
    }

    public function bienes(): MorphToMany
    {
        return $this->garantiasComo(Bien::class);
    }

    public function vehiculos(): MorphToMany
    {
        return $this->garantiasComo(Vehiculo::class);
    }

    public function inmuebles(): MorphToMany
    {
        return $this->garantiasComo(Inmueble::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function supervisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisado_por');
    }

    public function aprobadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function refrendoDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refrendo_de_credito_id');
    }

    public function refrendos(): HasMany
    {
        return $this->hasMany(self::class, 'refrendo_de_credito_id');
    }

    public function adendaDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adenda_de_credito_id');
    }

    public function adendas(): HasMany
    {
        return $this->hasMany(self::class, 'adenda_de_credito_id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoCredito::class, 'credito_id');
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(CuotaCredito::class, 'credito_id')->orderBy('numero_cuota');
    }

    protected function diasEnMora(): Attribute
    {
        return Attribute::get(function (): int {
            if (! in_array($this->estado, ['vencido', 'en_venta'], true) || $this->fecha_vencimiento === null) {
                return 0;
            }

            return max(0, (int) $this->fecha_vencimiento->copy()->startOfDay()->diffInDays(now()->startOfDay()));
        });
    }

    protected static function newFactory(): CreditoFactory
    {
        return CreditoFactory::new();
    }
}
