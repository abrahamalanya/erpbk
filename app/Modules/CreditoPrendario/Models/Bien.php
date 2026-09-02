<?php

namespace App\Modules\CreditoPrendario\Models;

use App\Modules\Credito\Concerns\EsGarantia;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\BienFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bien extends Model
{
    /** @use HasFactory<BienFactory> */
    use BelongsToTenant, EsGarantia, HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'bienes';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'cliente_id',
        'registrado_por',
        'tipo',
        'nombre',
        'marca',
        'modelo',
        'serie',
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
    protected $appends = ['foto_cliente_producto_url', 'video_url'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valorizacion' => 'decimal:2',
            'precio_venta' => 'decimal:2',
        ];
    }

    protected static function newFactory(): BienFactory
    {
        return BienFactory::new();
    }
}
