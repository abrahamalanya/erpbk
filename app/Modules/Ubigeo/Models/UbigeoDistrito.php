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
}
