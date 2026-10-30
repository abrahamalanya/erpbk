<?php

namespace App\Modules\Ubicacion\Models;

use Illuminate\Database\Eloquent\Model;

class DispositivoGps extends Model
{
    protected $table = 'dispositivos_gps';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['vinculado_en' => 'immutable_datetime'];
    }
}
