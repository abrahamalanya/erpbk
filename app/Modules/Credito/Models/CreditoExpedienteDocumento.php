<?php

namespace App\Modules\Credito\Models;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\CreditoExpedienteDocumentoFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Una imagen del expediente de un crédito hipotecario. El PDF del
 * documento "expediente" las embebe, agrupadas por rol + sección.
 */
class CreditoExpedienteDocumento extends Model
{
    /** @use HasFactory<CreditoExpedienteDocumentoFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'credito_expediente_documentos';

    /** @var list<string> */
    public const ROLES = ['deudor', 'aval1', 'aval2', 'inmueble'];

    /** @var list<string> */
    public const SECCIONES = [
        'dni', 'casa', 'negocio', 'ubicacion_maps', 'croquis', 'terreno',
        'trabajo', 'suministro', 'recibo_servicio', 'central_riesgo',
        'copia_literal', 'certificado_literal',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'credito_id', 'empresa_id', 'subido_por', 'rol', 'seccion', 'path', 'orden',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['url'];

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class);
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->path
            ? Storage::disk('public')->url($this->path)
            : null);
    }

    protected static function newFactory(): CreditoExpedienteDocumentoFactory
    {
        return CreditoExpedienteDocumentoFactory::new();
    }
}
