<?php

namespace App\Modules\Sistemas\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ConfiguracionSistema extends Model
{
    protected $table = 'configuraciones_sistema';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nombre_app',
        'favicon_path',
    ];

    /**
     * @var list<string>
     */
    protected $appends = ['favicon_url'];

    /**
     * There is only ever one row: the global platform branding. Self-heals
     * (creates the row with its DB defaults) if it hasn't been touched yet,
     * so the public GET /configuracion endpoint the login page depends on
     * never 404s.
     */
    public static function actual(): self
    {
        return static::query()->firstOrCreate([], ['nombre_app' => 'umax']);
    }

    protected function faviconUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->favicon_path
            ? Storage::disk('public')->url($this->favicon_path)
            : null);
    }
}
