<?php

namespace App\Nucleo\Http\Middleware;

use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Models\Credito;
use App\Modules\Sistemas\Services\AccesoModulo;
use App\Modules\Sistemas\Services\ModuloService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePerfilAcceso
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user->unsetRelation('perfilAcceso');
        if ($user->perfil_acceso_id === null || $user->hasRole('sistemas')) {
            return $next($request);
        }

        $requeridos = AccesoModulo::requeridos($request);
        $modulos = app(ModuloService::class)->modulosEfectivos($user);
        if ($requeridos !== null) {
            abort_unless(array_intersect($requeridos, $modulos) !== [], 403, 'Tu perfil no permite acceder a este módulo.');
        }

        $tipoSolicitado = $request->input('tipo_credito');
        if (is_string($tipoSolicitado) && in_array($tipoSolicitado, AccesoModulo::TIPOS_CREDITO, true)) {
            abort_unless(in_array($tipoSolicitado, $modulos, true), 403, 'Tu perfil no permite este tipo de crédito.');
        }

        $credito = $request->route('credito');
        if ($credito !== null) {
            $tipo = $credito instanceof Credito ? $credito->tipo_credito : Credito::query()->findOrFail($credito)->tipo_credito;
            abort_unless(in_array($tipo, $modulos, true), 403, 'Tu perfil no permite este tipo de crédito.');
        }

        $cobro = $request->route('cobro');
        if ($cobro !== null) {
            $registro = $cobro instanceof Cobro ? $cobro : Cobro::query()->findOrFail($cobro);
            abort_unless(Credito::query()->whereKey($registro->credito_id)->exists(), 404);
        }

        return $next($request);
    }
}
