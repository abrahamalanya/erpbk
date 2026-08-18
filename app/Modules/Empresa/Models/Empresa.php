<?php

namespace App\Modules\Empresa\Models;

use App\Modules\Usuario\Models\User;
use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'estado',
    ];

    public function agencias(): HasMany
    {
        return $this->hasMany(Agencia::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected static function newFactory(): EmpresaFactory
    {
        return EmpresaFactory::new();
    }
}
