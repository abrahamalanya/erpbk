<?php

namespace App\Modules\CreditoPrendario\Models;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\BienFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Bien extends Model
{
    /** @use HasFactory<BienFactory> */
    use BelongsToTenant, HasFactory;

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
        'cantidad',
        'foto_cliente_producto_path',
        'estado',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['foto_cliente_producto_url'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valorizacion' => 'decimal:2',
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

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(BienFoto::class)->orderBy('orden');
    }

    public function creditos(): BelongsToMany
    {
        return $this->belongsToMany(CreditoPrendario::class, 'bien_credito_prendario', 'bien_id', 'credito_prendario_id')
            ->withTimestamps();
    }

    /**
     * Bienes not currently backing any crédito that hasn't been resolved
     * (liquidado) yet — available to attach to a new crédito.
     */
    public function scopeDisponibles(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'creditos',
            fn (Builder $q) => $q->whereIn('estado', ['pendiente', 'aprobado', 'activo', 'vencido', 'en_venta'])
        );
    }

    protected function fotoClienteProductoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->foto_cliente_producto_path
            ? Storage::disk('public')->url($this->foto_cliente_producto_path)
            : null);
    }

    protected static function newFactory(): BienFactory
    {
        return BienFactory::new();
    }
}
