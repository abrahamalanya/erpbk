<?php

namespace App\Nucleo\Scopes;

use App\Modules\Credito\Models\Credito;
use App\Modules\Sistemas\Services\ModuloService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class PerfilNotificacionScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $actor = Auth::user();
        if ($actor === null || $actor->perfil_acceso_id === null || $actor->hasRole('sistemas')) {
            return;
        }

        $builder->where(fn (Builder $q) => $q->whereNull('data->credito_id')->orWhereIn('data->credito_id', Credito::query()->select('creditos_prendarios.id')));
        foreach (['billetaje_id' => 'billetajes', 'venta_id' => 'ventas', 'interes_id' => 'tienda_solicitudes'] as $key => $modulo) {
            if (! app(ModuloService::class)->perfilPermite($actor, $modulo)) {
                $builder->whereNull('data->'.$key);
            }
        }
    }
}
