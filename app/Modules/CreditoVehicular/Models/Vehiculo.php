<?php

namespace App\Modules\CreditoVehicular\Models;

use App\Modules\Credito\Concerns\EsGarantia;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\VehiculoFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Garantía de un crédito vehicular: los datos de la tarjeta de propiedad
 * del vehículo. Comparte con Bien el comportamiento de garantía
 * (EsGarantia): dueños, fotos, vínculo con créditos y scope "disponibles".
 */
class Vehiculo extends Model
{
    /** @use HasFactory<VehiculoFactory> */
    use BelongsToTenant, EsGarantia, HasFactory;

    protected $table = 'vehiculos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'cliente_id',
        'registrado_por',
        'placa',
        'motor',
        'serie',
        'color',
        'marca',
        'modelo',
        'anio',
        'clase',
        'propietario',
        'tiene_soat',
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
            'tiene_soat' => 'boolean',
            'anio' => 'integer',
            'valorizacion' => 'decimal:2',
            'precio_venta' => 'decimal:2',
        ];
    }

    /**
     * A display label so the shared documento templates (which print
     * $garantia->nombre) render sensibly for a vehículo.
     */
    protected function nombre(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->marca} {$this->modelo}").' · '.$this->placa);
    }

    protected static function newFactory(): VehiculoFactory
    {
        return VehiculoFactory::new();
    }
}
