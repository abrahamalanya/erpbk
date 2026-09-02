<?php

namespace App\Modules\CreditoHipotecario\Http\Controllers;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Services\GarantiaHierarchyService;
use App\Modules\CreditoHipotecario\Http\Requests\StoreInmuebleRequest;
use App\Modules\CreditoHipotecario\Http\Requests\UpdateInmuebleRequest;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class InmuebleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GarantiaHierarchyService $hierarchy,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Inmueble::class);

        $query = Inmueble::query()->with(['agencia', 'cliente', 'registradoPor', 'fotos']);
        $query = $this->hierarchy->visibleQuery($query, request()->user());

        if (request()->filled('cliente_id')) {
            $query->where('cliente_id', request()->integer('cliente_id'));
        }

        if (request()->boolean('disponibles')) {
            $query->disponibles();
        }

        return $this->successResponse($query->paginate(15));
    }

    public function store(StoreInmuebleRequest $request): JsonResponse
    {
        Gate::authorize('create', Inmueble::class);

        $data = $request->validated();
        $actor = $request->user();
        $cliente = Cliente::findOrFail($data['cliente_id']);

        $inmueble = Inmueble::query()->create([
            'empresa_id' => $cliente->empresa_id,
            'agencia_id' => $cliente->agencia_id,
            'cliente_id' => $cliente->id,
            'registrado_por' => $actor->id,
            'partida_registral' => $data['partida_registral'],
            'oficina_registral' => $data['oficina_registral'] ?? null,
            'tipo_inmueble' => $data['tipo_inmueble'] ?? null,
            'direccion' => $data['direccion'],
            'distrito' => $data['distrito'] ?? null,
            'provincia' => $data['provincia'] ?? null,
            'departamento' => $data['departamento'] ?? null,
            'area_terreno' => $data['area_terreno'] ?? null,
            'area_construida' => $data['area_construida'] ?? null,
            'propietario' => $data['propietario'],
            'con_gravamen' => $data['con_gravamen'],
            'linderos' => $data['linderos'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'valorizacion' => $data['valorizacion'],
            'puntaje' => $data['puntaje'] ?? null,
            'estado' => 'en_garantia',
        ]);

        if ($request->hasFile('foto_cliente_producto')) {
            $inmueble->update([
                'foto_cliente_producto_path' => $request->file('foto_cliente_producto')->store("inmuebles/{$inmueble->id}", 'public'),
            ]);
        }

        if ($request->hasFile('video')) {
            $inmueble->update([
                'video_path' => $request->file('video')->store("inmuebles/{$inmueble->id}", 'public'),
            ]);
        }

        foreach ($request->file('fotos', []) as $orden => $foto) {
            $inmueble->fotos()->create([
                'path' => $foto->store("inmuebles/{$inmueble->id}", 'public'),
                'orden' => $orden,
            ]);
        }

        return $this->successResponse($inmueble->fresh(['fotos']), 'Inmueble registrado', 201);
    }

    public function show(Inmueble $inmueble): JsonResponse
    {
        Gate::authorize('view', $inmueble);

        return $this->successResponse($inmueble->load(['agencia', 'cliente', 'registradoPor', 'fotos', 'creditos']));
    }

    public function update(UpdateInmuebleRequest $request, Inmueble $inmueble): JsonResponse
    {
        Gate::authorize('update', $inmueble);

        if (! Inmueble::disponibles()->whereKey($inmueble->id)->exists()) {
            throw new DomainException('No puedes editar un inmueble mientras respalda un crédito activo.');
        }

        $data = $request->validated();

        $inmueble->update([
            'partida_registral' => $data['partida_registral'],
            'oficina_registral' => $data['oficina_registral'] ?? null,
            'tipo_inmueble' => $data['tipo_inmueble'] ?? null,
            'direccion' => $data['direccion'],
            'distrito' => $data['distrito'] ?? null,
            'provincia' => $data['provincia'] ?? null,
            'departamento' => $data['departamento'] ?? null,
            'area_terreno' => $data['area_terreno'] ?? null,
            'area_construida' => $data['area_construida'] ?? null,
            'propietario' => $data['propietario'],
            'con_gravamen' => $data['con_gravamen'],
            'linderos' => $data['linderos'] ?? null,
            'observacion' => $data['observacion'] ?? null,
            'valorizacion' => $data['valorizacion'],
            'puntaje' => $data['puntaje'] ?? null,
        ]);

        if ($request->hasFile('foto_cliente_producto')) {
            if ($inmueble->foto_cliente_producto_path) {
                Storage::disk('public')->delete($inmueble->foto_cliente_producto_path);
            }

            $inmueble->update([
                'foto_cliente_producto_path' => $request->file('foto_cliente_producto')->store("inmuebles/{$inmueble->id}", 'public'),
            ]);
        }

        if ($request->hasFile('video')) {
            if ($inmueble->video_path) {
                Storage::disk('public')->delete($inmueble->video_path);
            }

            $inmueble->update([
                'video_path' => $request->file('video')->store("inmuebles/{$inmueble->id}", 'public'),
            ]);
        }

        foreach ($request->file('fotos', []) as $orden => $foto) {
            $inmueble->fotos()->create([
                'path' => $foto->store("inmuebles/{$inmueble->id}", 'public'),
                'orden' => $inmueble->fotos()->count() + $orden,
            ]);
        }

        return $this->successResponse($inmueble->fresh(['fotos']), 'Inmueble actualizado');
    }
}
