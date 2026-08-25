<?php

namespace App\Modules\Caja\Models;

use Database\Factories\MovimientoFotoFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class MovimientoFoto extends Model
{
    /** @use HasFactory<MovimientoFotoFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'fotografiable_type',
        'fotografiable_id',
        'tipo',
        'path',
        'orden',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['url'];

    public function fotografiable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk('public')->url($this->path));
    }

    protected static function newFactory(): MovimientoFotoFactory
    {
        return MovimientoFotoFactory::new();
    }
}
