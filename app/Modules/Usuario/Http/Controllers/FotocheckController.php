<?php

namespace App\Modules\Usuario\Http\Controllers;

use App\Modules\Usuario\Http\Requests\FotocheckRequest;
use App\Modules\Usuario\Models\User;
use App\Modules\Usuario\Services\FotocheckService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fotocheck (tarjeta de identificación del personal): hoja A4 horizontal con
 * hasta 6 tarjetas por página, cada una con la parte frontal (foto, nombre,
 * rol, dni) y el aviso de intransferibilidad con QR del dni.
 */
class FotocheckController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FotocheckService $fotocheck) {}

    /**
     * Descarga el PDF con las tarjetas de los usuarios indicados. Misma
     * autoridad que "ver usuarios": solo se puede imprimir para alguien que el
     * actor ya puede ver.
     */
    public function pdf(FotocheckRequest $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $tarjetas = $this->fotocheck->tarjetas($this->usuariosVisibles($request));

        return $this->fotocheck->pdf($tarjetas);
    }

    /**
     * Los usuarios pedidos, en el orden del request, acotados a los que el
     * actor puede ver. Un id fuera de su alcance no produce tarjeta (ni error):
     * se ignora, para que un filtro de más en el frontend no rompa la hoja.
     *
     * @return list<User>
     */
    private function usuariosVisibles(FotocheckRequest $request): array
    {
        $actor = $request->user();

        return User::query()
            ->whereKey($request->usuarioIds())
            ->when($actor->hasRole('administrador_agencia'), fn (Builder $q) => $q->where('agencia_id', $actor->agencia_id))
            ->with(['roles', 'agencia', 'empresa'])
            ->get()
            ->sortBy(fn (User $u): int => array_search($u->id, $request->usuarioIds(), true))
            ->values()
            ->all();
    }
}
