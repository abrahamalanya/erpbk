<?php

namespace App\Modules\Cliente\Models;

use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\FichaSocioeconomicaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ficha socioeconómica del cliente (1:1). La consume el documento del mismo
 * nombre en los créditos hipotecarios. Los totales y la edad se calculan.
 */
class FichaSocioeconomica extends Model
{
    /** @use HasFactory<FichaSocioeconomicaFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'fichas_socioeconomicas';

    /** Columnas de monto de cada grupo económico — fuente única para fillable/casts/totales. */
    public const CAMPOS_INGRESOS = [
        'ing_conyuge', 'ing_renta1', 'ing_renta2', 'ing_renta3', 'ing_renta4',
        'ing_renta5', 'ing_otros_no_formales', 'ing_pension_judicial',
    ];

    public const CAMPOS_EGRESOS_PERSONALES = [
        'egp_alimentacion', 'egp_creditos', 'egp_educacion', 'egp_pasajes',
        'egp_agua', 'egp_luz', 'egp_telefono', 'egp_salud', 'egp_otros',
        'egp_impuestos', 'egp_cable',
    ];

    public const CAMPOS_EGRESOS_NEGOCIO = [
        'egn_alquiler', 'egn_equipo', 'egn_energia_electrica', 'egn_sueldos_cargas',
        'egn_agua', 'egn_luz', 'egn_atenciones_personal', 'egn_telefonia',
        'egn_otros_impuestos_tasas', 'egn_seguridad_limpieza', 'egn_suministros',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id', 'cliente_id',
        'grado_instruccion', 'profesion',
        'laboral_institucion', 'laboral_cargo', 'laboral_fecha_ingreso',
        ...self::CAMPOS_INGRESOS,
        ...self::CAMPOS_EGRESOS_PERSONALES,
        ...self::CAMPOS_EGRESOS_NEGOCIO,
        'viv_tenencia', 'viv_material', 'viv_habitaciones', 'viv_tipo',
        'viv_nro_pisos', 'viv_piso_vive', 'viv_agua', 'viv_telefono',
        'viv_redes_servicio', 'viv_bienes_muebles',
        'viv_total_activo_mueble', 'viv_total_activo_inmueble',
        'declarante_nombres', 'declarante_parentesco', 'declarante_direccion',
        'declarante_telefono', 'observaciones', 'responsable_ficha',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'total_ingresos', 'total_egresos_personales', 'total_egresos_negocio', 'total_neto',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $decimales = collect([
            ...self::CAMPOS_INGRESOS,
            ...self::CAMPOS_EGRESOS_PERSONALES,
            ...self::CAMPOS_EGRESOS_NEGOCIO,
            'viv_total_activo_mueble', 'viv_total_activo_inmueble',
        ])->mapWithKeys(fn (string $col): array => [$col => 'decimal:2'])->all();

        return [
            ...$decimales,
            'laboral_fecha_ingreso' => 'date',
            'viv_redes_servicio' => 'array',
            'viv_bienes_muebles' => 'array',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function familiares(): HasMany
    {
        return $this->hasMany(FichaSocioeconomicaFamiliar::class);
    }

    /**
     * @param  list<string>  $campos
     */
    private function sumar(array $campos): string
    {
        return (string) array_reduce(
            $campos,
            fn (string $carry, string $col): string => bcadd($carry, (string) ($this->getAttribute($col) ?? 0), 2),
            '0'
        );
    }

    protected function totalIngresos(): Attribute
    {
        return Attribute::get(fn (): string => $this->sumar(self::CAMPOS_INGRESOS));
    }

    protected function totalEgresosPersonales(): Attribute
    {
        return Attribute::get(fn (): string => $this->sumar(self::CAMPOS_EGRESOS_PERSONALES));
    }

    protected function totalEgresosNegocio(): Attribute
    {
        return Attribute::get(fn (): string => $this->sumar(self::CAMPOS_EGRESOS_NEGOCIO));
    }

    /** Ingresos − egresos personales − egresos negocio. */
    protected function totalNeto(): Attribute
    {
        return Attribute::get(fn (): string => bcsub(
            bcsub($this->total_ingresos, $this->total_egresos_personales, 2),
            $this->total_egresos_negocio,
            2
        ));
    }

    protected static function newFactory(): FichaSocioeconomicaFactory
    {
        return FichaSocioeconomicaFactory::new();
    }
}
