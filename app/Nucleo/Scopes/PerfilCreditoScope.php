<?php

namespace App\Nucleo\Scopes;

use App\Modules\Sistemas\Services\ModuloService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/** Restricts credit reads, including relationships, documents and aggregates. */
class PerfilCreditoScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $actor = Auth::user();
        if ($actor !== null && $actor->perfil_acceso_id !== null && ! $actor->hasRole('sistemas')) {
            $builder->whereIn($model->qualifyColumn('tipo_credito'), app(ModuloService::class)->modulosEfectivos($actor));
        }
    }
}
