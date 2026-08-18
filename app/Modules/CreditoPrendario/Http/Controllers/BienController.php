<?php

namespace App\Modules\CreditoPrendario\Http\Controllers;

use App\Modules\CreditoPrendario\Http\Requests\StoreBienRequest;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BienController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Bien::class);

        $actor = request()->user();
        $query = Bien::query()->with(['agencia', 'registradoPor', 'fotos']);

        if (! $actor->hasRole('sistemas') && ! $actor->hasAnyRole(['administrador_general', 'secretaria'])) {
            if ($actor->hasRole('administrador_agencia')) {
                $query->where('agencia_id', $actor->agencia_id);
            } else {
                $query->where('registrado_por', $actor->id);
            }
        }

        return $this->successResponse($query->paginate(15));
    }

    public function store(StoreBienRequest $request): JsonResponse
    {
        Gate::authorize('create', Bien::class);

        $data = $request->validated();
        $actor = $request->user();

        $bien = Bien::query()->create([
            'empresa_id' => $actor->hasAnyRole(['sistemas', 'administrador_general', 'secretaria'])
                ? Agencia::findOrFail($data['agencia_id'])->empresa_id
                : $actor->empresa_id,
            'agencia_id' => $actor->hasAnyRole(['sistemas', 'administrador_general', 'secretaria'])
                ? $data['agencia_id']
                : $actor->agencia_id,
            'registrado_por' => $actor->id,
            'tipo' => $data['tipo'],
            'nombre' => $data['nombre'],
            'marca' => $data['marca'] ?? null,
            'modelo' => $data['modelo'] ?? null,
            'serie' => $data['serie'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'valorizacion' => $data['valorizacion'],
            'cantidad' => $data['cantidad'] ?? 1,
            'estado' => 'en_garantia',
        ]);

        if ($request->hasFile('foto_cliente_producto')) {
            $bien->update([
                'foto_cliente_producto_path' => $request->file('foto_cliente_producto')->store("bienes/{$bien->id}", 'public'),
            ]);
        }

        foreach ($request->file('fotos', []) as $orden => $foto) {
            $bien->fotos()->create([
                'path' => $foto->store("bienes/{$bien->id}", 'public'),
                'orden' => $orden,
            ]);
        }

        return $this->successResponse($bien->fresh(['fotos']), 'Bien registrado', 201);
    }

    public function show(Bien $bien): JsonResponse
    {
        Gate::authorize('view', $bien);

        return $this->successResponse($bien->load(['agencia', 'registradoPor', 'fotos', 'creditos']));
    }
}
