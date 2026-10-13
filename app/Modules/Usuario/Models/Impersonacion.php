<?php

namespace App\Modules\Usuario\Models;

use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\ImpersonacionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de una sesión de "ingresar como" (impersonación): quién la inició,
 * a quién, con qué token de Sanctum, y cuándo empezó/terminó.
 * `finalizado_at` nulo significa que la sesión sigue activa — ver
 * ImpersonacionService.
 */
class Impersonacion extends Model
{
    /** @use HasFactory<ImpersonacionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Explicit: Eloquent's English pluralizer would guess "impersonacions".
     *
     * @var string
     */
    protected $table = 'impersonaciones';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'iniciado_por',
        'impersonado_id',
        'token_id',
        'motivo',
        'ip',
        'user_agent',
        'finalizado_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'finalizado_at' => 'datetime',
        ];
    }

    public function iniciadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'iniciado_por');
    }

    public function impersonado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonado_id');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->whereNull('finalizado_at');
    }

    protected static function newFactory(): ImpersonacionFactory
    {
        return ImpersonacionFactory::new();
    }
}
