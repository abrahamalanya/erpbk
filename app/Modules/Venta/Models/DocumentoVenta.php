<?php

namespace App\Modules\Venta\Models;

use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\DocumentoVentaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento generado de una venta (voucher, contrato_credito,
 * contrato_apartado, compra_venta, notarial). Mirror de DocumentoCredito.
 */
class DocumentoVenta extends Model
{
    /** @use HasFactory<DocumentoVentaFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'documentos_venta';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'venta_id',
        'empresa_id',
        'tipo',
        'datos',
        'archivo_firmado_path',
        'generado_por',
        'generado_at',
        'impreso_at',
        'firmado_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'datos' => 'array',
            'generado_at' => 'datetime',
            'impreso_at' => 'datetime',
            'firmado_at' => 'datetime',
        ];
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }

    protected static function newFactory(): DocumentoVentaFactory
    {
        return DocumentoVentaFactory::new();
    }
}
