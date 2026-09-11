<?php

namespace App\Modules\Ubigeo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo global (INEI), no tenant-scoped — compartido por todas las empresas.
 */
class UbigeoProvincia extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['ubigeo_departamento_id', 'codigo', 'nombre'];

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(UbigeoDepartamento::class, 'ubigeo_departamento_id');
    }

    public function distritos(): HasMany
    {
        return $this->hasMany(UbigeoDistrito::class)->orderBy('nombre');
    }
}
