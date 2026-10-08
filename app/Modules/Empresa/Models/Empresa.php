<?php

namespace App\Modules\Empresa\Models;

use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use App\Modules\Facturacion\Models\CredencialSunat;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Modules\Usuario\Models\User;
use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'prefijo',
        'ruc',
        'razon_social',
        'domicilio_legal',
        'domicilio_calle',
        'domicilio_numero',
        'domicilio_interior',
        'ubigeo_distrito_id',
        'correo_facturacion',
        'telefono_facturacion',
        'actividad_economica',
        'representante_legal',
        'apoderado_legal',
        'celular_cobranzas',
        'logo_path',
        'firma_path',
        'estado',
        'modo_facturacion',
    ];

    /**
     * @var list<string>
     */
    protected $appends = [
        'logo_url',
        'firma_url',
        'direccion_fiscal_completa',
        'modo_facturacion_actual',
        'credenciales_sunat_completas',
        'facturacion_habilitada',
    ];

    public function agencias(): HasMany
    {
        return $this->hasMany(Agencia::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function ubigeoDistrito(): BelongsTo
    {
        return $this->belongsTo(UbigeoDistrito::class);
    }

    public function credencialSunat(): HasOne
    {
        return $this->hasOne(CredencialSunat::class);
    }

    public function configuracionFacturacion(): HasOne
    {
        return $this->hasOne(ConfiguracionFacturacion::class);
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

    /**
     * Dirección fiscal armada como una línea, en el orden que exige SUNAT:
     * calle, número, interior, y el distrito como respaldo cuando la dirección
     * estructurada aún no se cargó (una empresa puede tener RUC y razón social
     * pero no haber pasado por la pantalla de datos fiscales).
     *
     * @return array{direccion: ?string, ubigeo: ?string}
     */
    public function datosFiscales(): array
    {
        $partes = collect([
            $this->domicilio_calle,
            $this->domicilio_numero ? 'N.° '.$this->domicilio_numero : null,
            $this->domicilio_interior,
        ])->filter()->implode(' ');

        if ($partes === '') {
            $partes = $this->domicilio_legal;
        }

        if ($partes === '' && $this->relationLoaded('ubigeoDistrito') && $this->ubigeoDistrito) {
            $partes = $this->ubigeoDistrito->nombre;
        }

        return [
            'direccion' => $partes !== '' ? $partes : null,
            'ubigeo' => $this->ubigeoDistrito?->ubigeo(),
        ];
    }

    protected function direccionFiscalCompleta(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->datosFiscales()['direccion']);
    }

    /**
     * ¿Esta empresa puede emitir comprobantes? Falta el RUC o la dirección
     * fiscal estructurada que el XML exige.
     *
     * @return list<string> los motivos que faltan, vacío si está completa
     */
    public function datosFiscalesFaltantes(): array
    {
        $faltantes = [];

        if (blank($this->ruc)) {
            $faltantes[] = 'RUC';
        }

        if (blank($this->razon_social)) {
            $faltantes[] = 'razón social';
        }

        if (blank($this->domicilio_calle) || ! $this->ubigeo_distrito_id) {
            $faltantes[] = 'domicilio fiscal';
        }

        return $faltantes;
    }

    /**
     * Modo de facturación actual (beta/produccion) desde configuracion_facturacion.
     * Si no existe configuración, usa el campo modo_facturacion de la empresa.
     */
    protected function modoFacturacionActual(): Attribute
    {
        return Attribute::get(function (): string {
            return $this->configuracionFacturacion?->modo ?? $this->modo_facturacion ?? 'beta';
        });
    }

    /**
     * ¿Las credenciales SUNAT están completas para emitir?
     */
    protected function credencialesSunatCompletas(): Attribute
    {
        return Attribute::get(function (): bool {
            return $this->credencialSunat?->estaCompleta() ?? false;
        });
    }

    /**
     * ¿La facturación está habilitada y lista para emitir?
     * Requiere: config activa, credenciales completas, certificado vigente, datos fiscales OK.
     */
    protected function facturacionHabilitada(): Attribute
    {
        return Attribute::get(function (): bool {
            $config = $this->configuracionFacturacion;
            $cred = $this->credencialSunat;

            if (! $config?->activo) {
                return false;
            }

            if (! $cred || ! $cred->estaCompleta()) {
                return false;
            }

            if ($cred->estaVencido()) {
                return false;
            }

            return $this->datosFiscalesFaltantes() === [];
        });
    }
}
