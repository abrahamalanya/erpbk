<?php

namespace App\Modules\Empresa\Models;

use App\Modules\Usuario\Models\User;
use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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
        'ruc',
        'razon_social',
        'domicilio_legal',
        'actividad_economica',
        'representante_legal',
        'logo_path',
        'firma_path',
        'estado',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['logo_url', 'firma_url'];

    public function agencias(): HasMany
    {
        return $this->hasMany(Agencia::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->logo_path
            ? Storage::disk('public')->url($this->logo_path)
            : null);
    }

    protected function firmaUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->firma_path
            ? Storage::disk('public')->url($this->firma_path)
            : null);
    }

    protected static function newFactory(): EmpresaFactory
    {
        return EmpresaFactory::new();
    }
}
