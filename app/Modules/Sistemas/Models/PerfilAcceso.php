<?php

namespace App\Modules\Sistemas\Models;

use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PerfilAcceso extends Model
{
    protected $table = 'perfiles_acceso';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['nombre', 'descripcion', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function modulos(): BelongsToMany
    {
        return $this->belongsToMany(Modulo::class, 'perfil_acceso_modulo');
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
