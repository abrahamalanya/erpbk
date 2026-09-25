<?php

namespace App\Modules\Cliente\Models;

use App\Modules\Credito\Models\Credito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Sistemas\Models\PermisoTemporal;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Cliente extends Model
{
    /** @use HasFactory<ClienteFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'agencia_id',
        'asesor_id',
        'registrado_por',
        'nombre',
        'apellido',
        'tipo_documento',
        'numero_documento',
        'fecha_nacimiento',
        'sexo',
        'estado_civil',
        'email',
        'telefono',
        'direccion',
        'ubigeo_distrito_id',
        'referencia',
        'latitud',
        'longitud',
        'direccion_negocio',
        'ubigeo_distrito_negocio_id',
        'referencia_negocio',
        'latitud_negocio',
        'longitud_negocio',
        'foto_cliente_path',
        'foto_dni_path',
        'foto_dni_reverso_path',
        'foto_casa_path',
        'foto_negocio_path',
        'estado',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'foto_cliente_url', 'foto_dni_url', 'foto_dni_reverso_url', 'foto_casa_url', 'foto_negocio_url', 'edad',
        'distrito', 'provincia', 'departamento',
        'distrito_negocio', 'provincia_negocio', 'departamento_negocio',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
            'latitud' => 'decimal:7',
            'longitud' => 'decimal:7',
            'latitud_negocio' => 'decimal:7',
            'longitud_negocio' => 'decimal:7',
        ];
    }

    /** Edad en años cumplidos a partir de fecha_nacimiento. */
    protected function edad(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->fecha_nacimiento
            ? (int) abs($this->fecha_nacimiento->diffInYears(now()))
            : null);
    }

    public function fichaSocioeconomica(): HasOne
    {
        return $this->hasOne(FichaSocioeconomica::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asesor_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function creditos(): HasMany
    {
        return $this->hasMany(Credito::class);
    }

    public function permisosTemporales(): HasMany
    {
        return $this->hasMany(PermisoTemporal::class);
    }

    public function ubigeoDistrito(): BelongsTo
    {
        return $this->belongsTo(UbigeoDistrito::class);
    }

    /** Distrito de la dirección del negocio/trabajo del cliente — opcional, separado de la casa. */
    public function ubigeoDistritoNegocio(): BelongsTo
    {
        return $this->belongsTo(UbigeoDistrito::class, 'ubigeo_distrito_negocio_id');
    }

    /**
     * distrito/provincia/departamento (de la casa) como texto plano, para
     * mostrarlos sin que el frontend tenga que resolver la cadena de
     * relaciones — se derivan de ubigeoDistrito, ya no son columnas propias.
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

    protected function distritoNegocio(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->ubigeoDistritoNegocio?->nombre);
    }

    protected function provinciaNegocio(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->ubigeoDistritoNegocio?->provincia?->nombre);
    }

    protected function departamentoNegocio(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->ubigeoDistritoNegocio?->provincia?->departamento?->nombre);
    }

    protected function fotoClienteUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->foto_cliente_path
            ? Storage::disk('public')->url($this->foto_cliente_path)
            : null);
    }

    protected function fotoDniUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->foto_dni_path
            ? Storage::disk('public')->url($this->foto_dni_path)
            : null);
    }

    protected function fotoDniReversoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->foto_dni_reverso_path
            ? Storage::disk('public')->url($this->foto_dni_reverso_path)
            : null);
    }

    protected function fotoCasaUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->foto_casa_path
            ? Storage::disk('public')->url($this->foto_casa_path)
            : null);
    }

    protected function fotoNegocioUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->foto_negocio_path
            ? Storage::disk('public')->url($this->foto_negocio_path)
            : null);
    }

    protected static function newFactory(): ClienteFactory
    {
        return ClienteFactory::new();
    }
}
