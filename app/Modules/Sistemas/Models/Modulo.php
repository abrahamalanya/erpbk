<?php

namespace App\Modules\Sistemas\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Modulo extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['key', 'nombre', 'grupo'];

    protected $appends = ['revision'];

    protected static function booted(): void
    {
        static::updating(function (Modulo $modulo): void {
            if ($modulo->isDirty('key')) {
                throw new \DomainException('La clave técnica de un módulo no se puede modificar.');
            }
        });
    }

    protected function revision(): Attribute
    {
        return Attribute::get(fn (): string => hash('sha256', json_encode([$this->key, $this->nombre, $this->grupo], JSON_THROW_ON_ERROR)));
    }

    public function perfilesAcceso(): BelongsToMany
    {
        return $this->belongsToMany(PerfilAcceso::class, 'perfil_acceso_modulo');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_modulo');
    }
}
