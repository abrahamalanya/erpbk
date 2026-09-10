<?php

namespace App\Modules\Cliente\Models;

use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Concerns\BelongsToTenant;
use Database\Factories\FichaSocioeconomicaFamiliarFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un familiar conviviente listado en la sección 3 de la ficha
 * socioeconómica. La lista se reemplaza completa en cada guardado.
 */
class FichaSocioeconomicaFamiliar extends Model
{
    /** @use HasFactory<FichaSocioeconomicaFamiliarFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'ficha_socioeconomica_familiares';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ficha_socioeconomica_id',
        'empresa_id',
        'nombres',
        'edad',
        'parentesco',
        'estado_civil',
        'ocupacion',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['edad' => 'integer'];
    }

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(FichaSocioeconomica::class, 'ficha_socioeconomica_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    protected static function newFactory(): FichaSocioeconomicaFamiliarFactory
    {
        return FichaSocioeconomicaFamiliarFactory::new();
    }
}
