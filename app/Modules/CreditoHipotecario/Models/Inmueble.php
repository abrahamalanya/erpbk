<?php

namespace App\Modules\CreditoHipotecario\Models;

use App\Modules\Credito\Concerns\EsGarantia;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\InmuebleFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'distrito',
        'provincia',
        'departamento',
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
    protected $appends = ['nombre', 'foto_cliente_producto_url', 'video_url'];

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

    protected static function newFactory(): InmuebleFactory
    {
        return InmuebleFactory::new();
    }
}
