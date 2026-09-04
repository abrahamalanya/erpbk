<?php

namespace App\Modules\CreditoVehicular\Http\Controllers;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Services\GarantiaHierarchyService;
use App\Modules\CreditoVehicular\Http\Requests\StoreVehiculoRequest;
use App\Modules\CreditoVehicular\Http\Requests\UpdateVehiculoRequest;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class VehiculoController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly GarantiaHierarchyService $hierarchy,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Vehiculo::class);

        $query = Vehiculo::query()->with(['agencia', 'cliente', 'registradoPor', 'fotos']);
        $query = $this->hierarchy->visibleQuery($query, request()->user());

        if (request()->filled('cliente_id')) {
            $query->where('cliente_id', request()->integer('cliente_id'));
        }

        if (request()->boolean('disponibles')) {
            $query->disponibles();
        }

        return $this->successResponse($query->paginate(15));
    }

    public function store(StoreVehiculoRequest $request): JsonResponse
    {
        Gate::authorize('create', Vehiculo::class);

        $data = $request->validated();
        $actor = $request->user();
        $cliente = Cliente::findOrFail($data['cliente_id']);

        $vehiculo = Vehiculo::query()->create([
            'empresa_id' => $cliente->empresa_id,
            'agencia_id' => $cliente->agencia_id,
            'cliente_id' => $cliente->id,
            'registrado_por' => $actor->id,
            'placa' => $data['placa'],
            'motor' => $data['motor'],
            'serie' => $data['serie'],
            'color' => $data['color'],
            'marca' => $data['marca'],
            'modelo' => $data['modelo'] ?? null,
            'anio' => $data['anio'] ?? null,
            'clase' => $data['clase'] ?? null,
            'propietario' => $data['propietario'],
            'tiene_soat' => $data['tiene_soat'],
            'dejo_llave' => $data['dejo_llave'],
            'dejo_tarjeta_propiedad' => $data['dejo_tarjeta_propiedad'],
            'observacion' => $data['observacion'] ?? null,
            'valorizacion' => $data['valorizacion'],
            'puntaje' => $data['puntaje'] ?? null,
            'estado' => 'en_garantia',
        ]);

        if ($request->hasFile('foto_cliente_producto')) {
            $vehiculo->update([
                'foto_cliente_producto_path' => $request->file('foto_cliente_producto')->store("vehiculos/{$vehiculo->id}", 'public'),
            ]);
        }

        if ($request->hasFile('video')) {
            $vehiculo->update([
                'video_path' => $request->file('video')->store("vehiculos/{$vehiculo->id}", 'public'),
            ]);
        }

        foreach ($request->file('fotos', []) as $orden => $foto) {
            $vehiculo->fotos()->create([
                'path' => $foto->store("vehiculos/{$vehiculo->id}", 'public'),
                'orden' => $orden,
            ]);
        }

        return $this->successResponse($vehiculo->fresh(['fotos']), 'Vehículo registrado', 201);
    }

    public function show(Vehiculo $vehiculo): JsonResponse
    {
        Gate::authorize('view', $vehiculo);

        return $this->successResponse($vehiculo->load(['agencia', 'cliente', 'registradoPor', 'fotos', 'creditos']));
    }

    public function update(UpdateVehiculoRequest $request, Vehiculo $vehiculo): JsonResponse
    {
        Gate::authorize('update', $vehiculo);

        if (! Vehiculo::disponibles()->whereKey($vehiculo->id)->exists()) {
            throw new DomainException('No puedes editar un vehículo mientras respalda un crédito activo.');
        }

        $data = $request->validated();

        $vehiculo->update([
            'placa' => $data['placa'],
            'motor' => $data['motor'],
            'serie' => $data['serie'],
            'color' => $data['color'],
            'marca' => $data['marca'],
            'modelo' => $data['modelo'] ?? null,
            'anio' => $data['anio'] ?? null,
            'clase' => $data['clase'] ?? null,
            'propietario' => $data['propietario'],
            'tiene_soat' => $data['tiene_soat'],
            'dejo_llave' => $data['dejo_llave'],
            'dejo_tarjeta_propiedad' => $data['dejo_tarjeta_propiedad'],
            'observacion' => $data['observacion'] ?? null,
            'valorizacion' => $data['valorizacion'],
            'puntaje' => $data['puntaje'] ?? null,
        ]);

        if ($request->hasFile('foto_cliente_producto')) {
            if ($vehiculo->foto_cliente_producto_path) {
                Storage::disk('public')->delete($vehiculo->foto_cliente_producto_path);
            }

            $vehiculo->update([
                'foto_cliente_producto_path' => $request->file('foto_cliente_producto')->store("vehiculos/{$vehiculo->id}", 'public'),
            ]);
        }

        if ($request->hasFile('video')) {
            if ($vehiculo->video_path) {
                Storage::disk('public')->delete($vehiculo->video_path);
            }

            $vehiculo->update([
                'video_path' => $request->file('video')->store("vehiculos/{$vehiculo->id}", 'public'),
            ]);
        }

        foreach ($request->file('fotos', []) as $orden => $foto) {
            $vehiculo->fotos()->create([
                'path' => $foto->store("vehiculos/{$vehiculo->id}", 'public'),
                'orden' => $vehiculo->fotos()->count() + $orden,
            ]);
        }

        return $this->successResponse($vehiculo->fresh(['fotos']), 'Vehículo actualizado');
    }
}
