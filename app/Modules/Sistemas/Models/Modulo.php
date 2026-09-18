<?php

namespace App\Modules\Sistemas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Modulo extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['key', 'nombre', 'grupo'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_modulo');
    }
}
