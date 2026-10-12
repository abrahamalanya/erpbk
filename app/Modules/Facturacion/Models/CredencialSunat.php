<?php

namespace App\Modules\Facturacion\Models;

use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CredencialSunatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Credenciales de SUNAT de una empresa: usuario/clave SOL y el certificado
 * digital .pfx con el que se firma cada comprobante.
 *
 * `clave_sol` y `certificado_password` usan el cast `encrypted`: en la base de
 * datos quedan cifrados, nunca en texto plano. El `.pfx` se guarda en disco
 * PRIVADO; `certificado_path` solo guarda la ruta.
 *
 * Nunca van en `config/` ni en `.env`: la instancia es multi-empresa y cada
 * financiera tiene su propio certificado (FACTURACION.md §5.11).
 */
class CredencialSunat extends Model
{
    /** @use HasFactory<CredencialSunatFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'credenciales_sunat';

    /** @var list<string> */
    protected $fillable = [
        'empresa_id',
        'usuario_sol',
        'clave_sol',
        'certificado_path',
        'certificado_password',
        'razon_social_certificado',
        'certificado_vence_en',
        'certificado_cargado_at',
        'ultima_validacion_at',
        'ultima_validacion_ok',
        'ultima_validacion_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clave_sol' => 'encrypted',
            'certificado_password' => 'encrypted',
            'certificado_vence_en' => 'date',
            'certificado_cargado_at' => 'datetime',
            'ultima_validacion_at' => 'datetime',
            'ultima_validacion_ok' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * ¿Está completo lo mínimo para intentar firmar y enviar?
     */
    public function estaCompleta(): bool
    {
        return filled($this->usuario_sol)
            && filled($this->clave_sol)
            && filled($this->certificado_path);
    }

    /**
     * Días para la vencimiento del certificado. Negativo = ya venció, y eso
     * bloquea toda emisión de la empresa.
     */
    public function diasParaVencer(): ?int
    {
        if ($this->certificado_vence_en === null) {
            return null;
        }

        return $this->certificado_vence_en->diffInDays(now())->inclusivedays * ($this->certificado_vence_en->isPast() ? -1 : 1);
    }

    public function estaVencido(): bool
    {
        return $this->certificado_vence_en !== null && $this->certificado_vence_en->isPast();
    }

    protected static function newFactory(): CredencialSunatFactory
    {
        return CredencialSunatFactory::new();
    }
}
