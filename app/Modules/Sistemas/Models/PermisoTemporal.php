<?php

namespace App\Modules\Sistemas\Models;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermisoTemporal extends Model
{
    use BelongsToTenant;

    /**
     * @var string
     */
    protected $table = 'permisos_temporales';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'empresa_id',
        'usuario_id',
        'cliente_id',
        'permiso',
        'motivo',
        'concedido_por',
        'concedido_at',
        'expira_at',
        'revocado_at',
        'revocado_por',
        'motivo_revocacion',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['estado'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'concedido_at' => 'datetime',
            'expira_at' => 'datetime',
            'revocado_at' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function concedidoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concedido_por');
    }

    public function revocadoPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revocado_por');
    }

    public function estaActivo(): bool
    {
        return $this->revocado_at === null && $this->expira_at?->isFuture() === true;
    }

    protected function estado(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->revocado_at !== null) {
                return 'revocado';
            }

            return $this->expira_at?->isFuture() ? 'vigente' : 'expirado';
        });
    }
}
