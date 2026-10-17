<?php

namespace App\Nucleo\Scopes;

use App\Modules\Credito\Models\Credito;
use App\Modules\Facturacion\Models\Comprobante;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class PerfilComprobanteScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $actor = Auth::user();
        if ($actor === null || $actor->perfil_acceso_id === null || $actor->hasRole('sistemas')) {
            return;
        }

        $creditos = Credito::query()->select('creditos_prendarios.id');
        $originales = Comprobante::withoutGlobalScope(self::class)->select('comprobantes.id')
            ->where('origen_tipo', 'credito')->whereIn('origen_id', clone $creditos);

        $builder->where(function (Builder $query) use ($creditos, $originales): void {
            $query->where(fn (Builder $q) => $q->where('origen_tipo', 'credito')->whereIn('origen_id', $creditos))
                ->orWhere(fn (Builder $q) => $q->where('origen_tipo', 'nota_credito')->whereIn('origen_id', $originales));
        });
    }
}
