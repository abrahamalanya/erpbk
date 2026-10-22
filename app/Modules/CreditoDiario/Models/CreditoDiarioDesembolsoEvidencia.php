<?php

namespace App\Modules\CreditoDiario\Models;

use App\Modules\Credito\Models\Credito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Evidencia de desembolso (video + GPS + fecha/hora) de un crédito diario —
 * una fila por crédito, ver migración.
 */
class CreditoDiarioDesembolsoEvidencia extends Model
{
    protected $table = 'credito_diario_desembolso_evidencias';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'credito_id',
        'empresa_id',
        'agencia_id',
        'subido_por',
        'video_path',
        'video_mime_type',
        'video_size_bytes',
        'latitud',
        'longitud',
        'precision_metros',
        'capturado_en',
        'recibido_en',
        'confirmado_en',
        'estado',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['url'];

    protected function casts(): array
    {
        return [
            'latitud' => 'float',
            'longitud' => 'float',
            'precision_metros' => 'float',
            'video_size_bytes' => 'integer',
            'capturado_en' => 'datetime',
            'recibido_en' => 'datetime',
            'confirmado_en' => 'datetime',
        ];
    }

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

    public function agencia(): BelongsTo
    {
        return $this->belongsTo(Agencia::class);
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->video_path
            ? Storage::disk('public')->url($this->video_path)
            : null);
    }
}
