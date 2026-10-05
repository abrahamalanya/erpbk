<?php

namespace App\Modules\Usuario\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nombre',
        'apellido',
        'dni',
        'telefono',
        'email',
        'password',
        'estado',
        'empresa_id',
        'agencia_id',
        'supervisor_id',
        'modulos',
        'foto_path',
        'qr_yape_path',
        'fecha_nacimiento',
        'direccion',
        'referencia',
        'ubigeo_distrito_id',
        'latitud',
        'longitud',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'foto_url',
        'qr_yape_url',
        'distrito',
        'provincia',
        'departamento',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'modulos' => 'array',
            'fecha_nacimiento' => 'date',
            'latitud' => 'decimal:7',
            'longitud' => 'decimal:7',
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

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function subordinados(): HasMany
    {
        return $this->hasMany(User::class, 'supervisor_id');
    }

    public function ubigeoDistrito(): BelongsTo
    {
        return $this->belongsTo(UbigeoDistrito::class);
    }

    /**
     * distrito/provincia/departamento como texto plano para que el frontend
     * no tenga que resolver la cadena de relaciones — se derivan de
     * ubigeoDistrito, no son columnas propias (mismo criterio que Cliente).
     */
    protected function distrito(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->ubigeoDistrito?->nombre);
    }

    protected function provincia(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->ubigeoDistrito?->provincia?->nombre);
    }

    protected function departamento(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->ubigeoDistrito?->provincia?->departamento?->nombre);
    }

    protected function fotoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->foto_path
            ? Storage::disk('public')->url($this->foto_path)
            : null);
    }

    protected function qrYapeUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->qr_yape_path
            ? Storage::disk('public')->url($this->qr_yape_path)
            : null);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
