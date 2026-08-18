<?php

namespace App\Modules\CreditoPrendario\Models;

use Database\Factories\BienFotoFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class BienFoto extends Model
{
    /** @use HasFactory<BienFotoFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'bien_id',
        'path',
        'orden',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['url'];

    public function bien(): BelongsTo
    {
        return $this->belongsTo(Bien::class);
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk('public')->url($this->path));
    }

    protected static function newFactory(): BienFotoFactory
    {
        return BienFotoFactory::new();
    }
}
