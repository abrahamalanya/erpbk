<?php

namespace App\Modules\Ubigeo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo global (INEI), no tenant-scoped — compartido por todas las empresas.
 */
class UbigeoDepartamento extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['codigo', 'nombre'];

    public function provincias(): HasMany
    {
        return $this->hasMany(UbigeoProvincia::class)->orderBy('nombre');
    }
}
