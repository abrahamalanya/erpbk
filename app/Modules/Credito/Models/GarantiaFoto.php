<?php

namespace App\Modules\Credito\Models;

use Database\Factories\GarantiaFotoFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * One photo of a garantía (Bien / Vehiculo / …), stored polymorphically so
 * every garantía model shares the same multi-photo set.
 */
class GarantiaFoto extends Model
{
    /** @use HasFactory<GarantiaFotoFactory> */
    use HasFactory;

    protected $table = 'garantia_fotos';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'garantia_type',
        'garantia_id',
        'path',
        'orden',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['url'];

    public function garantia(): MorphTo
    {
        return $this->morphTo();
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk('public')->url($this->path));
    }

    protected static function newFactory(): GarantiaFotoFactory
    {
        return GarantiaFotoFactory::new();
    }
}
