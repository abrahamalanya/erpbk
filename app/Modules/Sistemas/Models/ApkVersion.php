<?php

namespace App\Modules\Sistemas\Models;

use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApkVersion extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'application_id',
        'version',
        'build_number',
        'descripcion',
        'file_path',
        'file_name',
        'file_size',
        'sha256',
        'publicada',
        'publicada_at',
        'subido_por',
    ];

    protected function casts(): array
    {
        return [
            'build_number' => 'integer',
            'file_size' => 'integer',
            'publicada' => 'boolean',
            'publicada_at' => 'datetime',
        ];
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
