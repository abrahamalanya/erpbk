<?php

namespace App\Modules\Sistemas\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = ['name', 'guard_name'];

    /**
     * Módulos por defecto de este rol (ver ModuloService::modulosEfectivos()).
     */
    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(Modulo::class, 'role_modulo');
    }
}
