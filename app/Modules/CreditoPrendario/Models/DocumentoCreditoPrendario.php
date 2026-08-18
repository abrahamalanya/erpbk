<?php

namespace App\Modules\CreditoPrendario\Models;

use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\DocumentoCreditoPrendarioFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DocumentoCreditoPrendario extends Model
{
    /** @use HasFactory<DocumentoCreditoPrendarioFactory> */
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
        'pdf_path',
        'generado_por',
        'generado_at',
        'impreso_at',
        'firmado_at',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['pdf_url'];

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
        return $this->belongsTo(CreditoPrendario::class, 'credito_id');
    }

    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }

    protected function pdfUrl(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk('public')->url($this->pdf_path));
    }

    protected static function newFactory(): DocumentoCreditoPrendarioFactory
    {
        return DocumentoCreditoPrendarioFactory::new();
    }
}
