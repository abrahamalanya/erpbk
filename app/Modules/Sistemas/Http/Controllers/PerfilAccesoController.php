<?php

namespace App\Modules\Sistemas\Http\Controllers;

use App\Modules\Sistemas\Http\Requests\SavePerfilAccesoRequest;
use App\Modules\Sistemas\Models\Modulo;
use App\Modules\Sistemas\Models\PerfilAcceso;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PerfilAccesoController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', PerfilAcceso::class);

        return $this->successResponse(PerfilAcceso::query()->with('modulos')->orderBy('nombre')->get());
    }

    public function show(PerfilAcceso $perfilAcceso): JsonResponse
    {
        Gate::authorize('viewAny', PerfilAcceso::class);

        return $this->successResponse($perfilAcceso->load('modulos'));
    }

    public function store(SavePerfilAccesoRequest $request): JsonResponse
    {
        Gate::authorize('create', PerfilAcceso::class);
        $perfil = DB::transaction(function () use ($request): PerfilAcceso {
            $perfil = PerfilAcceso::query()->create($request->safe()->only(['nombre', 'descripcion', 'activo']));
            $perfil->modulos()->sync(Modulo::query()->whereIn('key', $request->validated('modulos'))->pluck('id'));

            return $perfil;
        });

        return $this->successResponse($perfil->load('modulos'), 'Perfil creado', 201);
    }

    public function update(SavePerfilAccesoRequest $request, PerfilAcceso $perfilAcceso): JsonResponse
    {
        Gate::authorize('update', $perfilAcceso);
        $perfil = DB::transaction(function () use ($request, $perfilAcceso): PerfilAcceso {
            $perfil = PerfilAcceso::query()->lockForUpdate()->findOrFail($perfilAcceso->id);
            abort_if($request->filled('updated_at') && ! $perfil->updated_at->equalTo($request->date('updated_at')), 409, 'El perfil cambió. Recarga antes de guardar.');
            abort_if(! $request->boolean('activo') && User::withoutGlobalScopes()->where('perfil_acceso_id', $perfil->id)->exists(), 409, 'Reasigna los usuarios antes de desactivar el perfil.');
            $perfil->update($request->safe()->only(['nombre', 'descripcion', 'activo']));
            $perfil->modulos()->sync(Modulo::query()->whereIn('key', $request->validated('modulos'))->pluck('id'));
            $perfil->touch();

            return $perfil;
        });

        return $this->successResponse($perfil->load('modulos'), 'Perfil actualizado');
    }
}
