<?php

namespace App\Modules\Tienda\Models;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\InteresArticuloFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A public "me interesa" submission from the storefront, pointed at any
 * garantía en venta (Bien, Vehiculo, …) through the polymorphic articulo.
 */
class InteresArticulo extends Model
{
    /** @use HasFactory<InteresArticuloFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'intereses';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'articulo_type',
        'articulo_id',
        'empresa_id',
        'agencia_id',
        'nombre',
        'telefono',
        'email',
        'mensaje',
        'atendido_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'atendido_at' => 'datetime',
        ];
    }

    public function articulo(): MorphTo
    {
        return $this->morphTo();
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    protected static function newFactory(): InteresArticuloFactory
    {
        return InteresArticuloFactory::new();
    }
}
