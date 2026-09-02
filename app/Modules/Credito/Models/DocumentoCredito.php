<?php

namespace App\Modules\Credito\Models;

use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\DocumentoCreditoFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DocumentoCredito extends Model
{
    /** @use HasFactory<DocumentoCreditoFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'documentos_credito_prendario';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'credito_id',
        'empresa_id',
        'tipo',
        'archivo_firmado_path',
        'generado_por',
        'generado_at',
        'impreso_at',
        'firmado_at',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['ver_url', 'archivo_firmado_url'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generado_at' => 'datetime',
            'impreso_at' => 'datetime',
            'firmado_at' => 'datetime',
        ];
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }

    /**
     * The API path that renders this document's PDF fresh on every request
     * (never stored on disk — see DocumentoCreditoService::renderizar()).
     */
    protected function verUrl(): Attribute
    {
        return Attribute::get(fn (): string => "/creditos-prendarios/{$this->credito_id}/documentos/{$this->id}/ver");
    }

    /**
     * The asesor's uploaded scan/photo of the physically signed document —
     * distinct from ver_url (the freshly-generated, never-signed PDF).
     */
    protected function archivoFirmadoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->archivo_firmado_path
            ? Storage::disk('public')->url($this->archivo_firmado_path)
            : null);
    }

    protected static function newFactory(): DocumentoCreditoFactory
    {
        return DocumentoCreditoFactory::new();
    }
}
