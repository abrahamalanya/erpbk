<?php

namespace App\Modules\CreditoHipotecario\Models;

use App\Modules\Credito\Concerns\EsGarantia;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\InmuebleFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Garantía de un crédito hipotecario: los datos de la partida registral
 * SUNARP del predio. Comparte con Bien / Vehiculo el comportamiento de
 * garantía (EsGarantia).
 */
class Inmueble extends Model
{
    /** @use HasFactory<InmuebleFactory> */
    use BelongsToTenant, EsGarantia, HasFactory;

    protected $table = 'inmuebles';

    /** Prefijo del código único de la garantía (ver EsGarantia::bootEsGarantia()). */
    public const CODIGO_PREFIJO = 'I';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'cliente_id',
        'registrado_por',
        'partida_registral',
        'oficina_registral',
        'tipo_inmueble',
        'direccion',
        'ubigeo_distrito_id',
        'area_terreno',
        'area_construida',
        'propietario',
        'con_gravamen',
        'linderos',
        'observacion',
        'valorizacion',
        'precio_venta',
        'puntaje',
        'foto_cliente_producto_path',
        'video_path',
        'estado',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['nombre', 'foto_cliente_producto_url', 'video_url', 'distrito', 'provincia', 'departamento'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'con_gravamen' => 'boolean',
            'area_terreno' => 'decimal:2',
            'area_construida' => 'decimal:2',
            'valorizacion' => 'decimal:2',
            'precio_venta' => 'decimal:2',
        ];
    }

    /**
     * A display label so the shared documento templates (which print
     * $garantia->nombre) render sensibly for an inmueble.
     */
    protected function nombre(): Attribute
    {
        return Attribute::get(fn (): string => trim(($this->tipo_inmueble ? $this->tipo_inmueble.' · ' : '').$this->direccion));
    }

    public function ubigeoDistrito(): BelongsTo
    {
        return $this->belongsTo(UbigeoDistrito::class);
    }

    /**
     * distrito/provincia/departamento como texto plano, derivados de
     * ubigeoDistrito — ya no son columnas propias.
     */
    protected function distrito(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->ubigeoDistrito?->nombre);
    }

    protected function provincia(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->ubigeoDistrito?->provincia?->nombre);
    }

    protected function departamento(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->ubigeoDistrito?->provincia?->departamento?->nombre);
    }

    protected static function newFactory(): InmuebleFactory
    {
        return InmuebleFactory::new();
    }
}
