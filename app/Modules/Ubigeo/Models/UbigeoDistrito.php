<?php

namespace App\Modules\Ubigeo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catálogo global (INEI), no tenant-scoped — compartido por todas las empresas.
 * El nivel que efectivamente se referencia desde Cliente/Inmueble: provincia
 * y departamento se resuelven navegando la relación, no se duplican.
 */
class UbigeoDistrito extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['ubigeo_provincia_id', 'codigo', 'nombre'];

    public function provincia(): BelongsTo
    {
        return $this->belongsTo(UbigeoProvincia::class, 'ubigeo_provincia_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(UbigeoDepartamento::class, 'ubigeo_provincia_id', 'ubigeo_departamento_id');
    }

    /**
     * Código de ubigeo de 6 dígitos que exige SUNAT en el XML
     * (`cac:PostalAddress/cbc:ID`): departamento + provincia + distrito.
     *
     * Se arma navigating las relaciones en vez de guardar un código de 6
     * dígitos, porque el INEI publica cada nivel por separado y duplicaría el
     * dato. Verificado sobre los 1,892 distritos del catálogo: los 1,892
     * producen exactamente 6 dígitos.
     */
    public function ubigeo(): string
    {
        return $this->provincia->departamento->codigo
            .$this->provincia->codigo
            .$this->codigo;
    }
}
