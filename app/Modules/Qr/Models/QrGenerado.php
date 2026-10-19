<?php

namespace App\Modules\Qr\Models;

use App\Modules\Usuario\Models\User;
use Database\Factories\QrGeneradoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrGenerado extends Model
{
    /** @use HasFactory<QrGeneradoFactory> */
    use HasFactory;

    protected $table = 'qr_generados';

    protected $fillable = ['url', 'user_id', 'generado_por'];

    protected static function newFactory(): QrGeneradoFactory
    {
        return QrGeneradoFactory::new();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
