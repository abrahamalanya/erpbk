<?php

namespace App\Modules\Usuario\Http\Controllers;

use App\Modules\Sistemas\Services\ModuloService;
use App\Modules\Usuario\Http\Requests\UpdateUserModulosRequest;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class UsuarioModuloController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ModuloService $modulos) {}

    public function show(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return $this->successResponse($this->estado($user));
    }

    public function update(UpdateUserModulosRequest $request, User $user): JsonResponse
    {
        Gate::authorize('update', $user);

        $this->modulos->asignar($user, $request->validated('modulos'));

        return $this->successResponse($this->estado($user), 'Módulos actualizados');
    }

    /**
     * @return array{disponibles: Collection<int, array{key: string, nombre: string, grupo: ?string}>, asignados: list<string>|null}
     */
    private function estado(User $user): array
    {
        return [
            'disponibles' => $this->modulos->catalogo()->map->only(['key', 'nombre', 'grupo'])->values(),
            'asignados' => $this->modulos->asignadosA($user),
        ];
    }
}
