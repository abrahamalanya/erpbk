<?php

namespace App\Modules\Empresa\Models;

use App\Modules\Caja\Models\Boveda;
use App\Modules\Caja\Models\Caja;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\CreditoPrendario\Models\CreditoPrendario;
use App\Modules\Tienda\Models\InteresBien;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\AgenciaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Agencia extends Model
{
    /** @use HasFactory<AgenciaFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'nombre',
        'estado',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function boveda(): HasOne
    {
        return $this->hasOne(Boveda::class);
    }

    public function cajas(): HasMany
    {
        return $this->hasMany(Caja::class);
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }

    public function bienes(): HasMany
    {
        return $this->hasMany(Bien::class);
    }

    public function creditosPrendarios(): HasMany
    {
        return $this->hasMany(CreditoPrendario::class);
    }

    public function configuracionesCreditoPrendario(): HasMany
    {
        return $this->hasMany(ConfiguracionCreditoPrendario::class);
    }

    public function interesesBien(): HasMany
    {
        return $this->hasMany(InteresBien::class);
    }

    protected static function newFactory(): AgenciaFactory
    {
        return AgenciaFactory::new();
    }
}
