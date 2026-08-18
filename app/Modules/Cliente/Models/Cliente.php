<?php

namespace App\Modules\Cliente\Models;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'telefono',
        'direccion',
        'referencia',
        'foto_cliente_path',
        'foto_dni_path',
        'foto_casa_path',
        'foto_negocio_path',
        'estado',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['foto_cliente_url', 'foto_dni_url', 'foto_casa_url', 'foto_negocio_url'];

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
