<?php

namespace App\Modules\Credito\Concerns;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\GarantiaFoto;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Storage;

/**
 * Shared behaviour for every garantía model (Bien, Vehiculo, Inmueble):
 * the common owner relations, the polymorphic link to créditos through the
 * credito_garantia pivot, the multi-foto set, and the "disponibles" scope
 * (not currently backing an unresolved crédito).
 *
 * Consuming models still declare their own $fillable, $casts, $appends and
 * any type-specific columns/accessors.
 */
trait EsGarantia
{
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

    public function fotos(): MorphMany
    {
        return $this->morphMany(GarantiaFoto::class, 'garantia')->orderBy('orden');
    }

    public function creditos(): MorphToMany
    {
        return $this->morphToMany(Credito::class, 'garantia', 'credito_garantia', 'garantia_id', 'credito_id')
            ->withTimestamps();
    }

    /**
     * Garantías not currently backing any crédito that hasn't been resolved
     * (liquidado) yet — available to attach to a new crédito.
     */
    public function scopeDisponibles(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'creditos',
            fn (Builder $q) => $q->whereIn('estado', ['pendiente', 'aprobado', 'activo', 'vencido', 'pendiente_conformidad', 'en_venta', 'liquidado_pendiente'])
        );
    }

    protected function fotoClienteProductoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->foto_cliente_producto_path
            ? Storage::disk('public')->url($this->foto_cliente_producto_path)
            : null);
    }

    protected function videoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->video_path
            ? Storage::disk('public')->url($this->video_path)
            : null);
    }
}
