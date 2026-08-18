<?php

namespace App\Modules\Caja\Models;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CajaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Caja extends Model
{
    /** @use HasFactory<CajaFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'empresa_id',
        'agencia_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function ciclos(): HasMany
    {
        return $this->hasMany(CajaCiclo::class);
    }

    public function cicloAbierto(): HasOne
    {
        return $this->hasOne(CajaCiclo::class)->where('estado', 'abierta');
    }

    protected static function newFactory(): CajaFactory
    {
        return CajaFactory::new();
    }
}
