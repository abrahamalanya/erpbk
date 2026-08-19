<?php

namespace App\Modules\CreditoPrendario\Http\Controllers;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Http\Requests\StoreBienRequest;
use App\Modules\CreditoPrendario\Http\Requests\UpdateBienRequest;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Services\BienHierarchyService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class BienController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BienHierarchyService $hierarchy,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Bien::class);

        $actor = request()->user();
        $query = Bien::query()->with(['agencia', 'cliente', 'registradoPor', 'fotos']);
        $query = $this->hierarchy->visibleQuery($query, $actor);

        if (request()->filled('cliente_id')) {
            $query->where('cliente_id', request()->integer('cliente_id'));
        }

        if (request()->boolean('disponibles')) {
            $query->disponibles();
        }

        return $this->successResponse($query->paginate(15));
    }

    public function store(StoreBienRequest $request): JsonResponse
    {
        Gate::authorize('create', Bien::class);

        $data = $request->validated();
        $actor = $request->user();
        $cliente = Cliente::findOrFail($data['cliente_id']);

        $bien = Bien::query()->create([
            'empresa_id' => $cliente->empresa_id,
            'agencia_id' => $cliente->agencia_id,
            'cliente_id' => $cliente->id,
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

        return $this->successResponse($bien->load(['agencia', 'cliente', 'registradoPor', 'fotos', 'creditos']));
    }

    public function update(UpdateBienRequest $request, Bien $bien): JsonResponse
    {
        Gate::authorize('update', $bien);

        if (! Bien::disponibles()->whereKey($bien->id)->exists()) {
            throw new DomainException('No puedes editar un bien mientras respalda un crédito activo.');
        }

        $data = $request->validated();

        $bien->update([
            'tipo' => $data['tipo'],
            'nombre' => $data['nombre'],
            'marca' => $data['marca'] ?? null,
            'modelo' => $data['modelo'] ?? null,
            'serie' => $data['serie'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'valorizacion' => $data['valorizacion'],
            'cantidad' => $data['cantidad'] ?? 1,
        ]);

        if ($request->hasFile('foto_cliente_producto')) {
            if ($bien->foto_cliente_producto_path) {
                Storage::disk('public')->delete($bien->foto_cliente_producto_path);
            }

            $bien->update([
                'foto_cliente_producto_path' => $request->file('foto_cliente_producto')->store("bienes/{$bien->id}", 'public'),
            ]);
        }

        foreach ($request->file('fotos', []) as $orden => $foto) {
            $bien->fotos()->create([
                'path' => $foto->store("bienes/{$bien->id}", 'public'),
                'orden' => $bien->fotos()->count() + $orden,
            ]);
        }

        return $this->successResponse($bien->fresh(['fotos']), 'Bien actualizado');
    }
}
