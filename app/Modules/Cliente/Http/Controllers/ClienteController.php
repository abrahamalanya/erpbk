<?php

namespace App\Modules\Cliente\Http\Controllers;

use App\Modules\Cliente\Http\Requests\AsignarClienteRequest;
use App\Modules\Cliente\Http\Requests\StoreClienteRequest;
use App\Modules\Cliente\Http\Requests\UpdateClienteRequest;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Services\ClienteHierarchyService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Services\ConsultaDniService;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ClienteController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ClienteHierarchyService $hierarchy,
        private readonly ConsultaDniService $consultaDni,
    ) {}

    public function consultarDni(string $dni): JsonResponse
    {
        Gate::authorize('create', Cliente::class);

        return $this->successResponse($this->consultaDni->consultar($dni));
    }

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Cliente::class);

        $query = Cliente::query()->with(['agencia', 'asesor', 'registradoPor']);
        $query = $this->hierarchy->visibleQuery($query, request()->user());

        if (request()->filled('q')) {
            $termino = trim((string) request()->string('q'));
            $query->where(function (Builder $sub) use ($termino): void {
                $sub->where('nombre', 'like', "%{$termino}%")
                    ->orWhere('apellido', 'like', "%{$termino}%")
                    ->orWhere('numero_documento', 'like', "%{$termino}%");
            });
        }

        $porPagina = max(1, min(request()->integer('per_page', 15), 100));

        return $this->successResponse($query->paginate($porPagina));
    }

    public function store(StoreClienteRequest $request): JsonResponse
    {
        Gate::authorize('create', Cliente::class);

        $data = $request->validated();
        $actor = $request->user();

        $cliente = Cliente::query()->create([
            'empresa_id' => $actor->hasRole('sistemas') ? $data['empresa_id'] : $actor->empresa_id,
            'agencia_id' => $this->hierarchy->resolveAgenciaId($actor, $data['agencia_id'] ?? null),
            'asesor_id' => $this->hierarchy->resolveAsesorId($actor),
            'registrado_por' => $actor->id,
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'],
            'tipo_documento' => $data['tipo_documento'],
            'numero_documento' => $data['numero_documento'],
            'telefono' => $data['telefono'] ?? null,
            'direccion' => $data['direccion'] ?? null,
            'referencia' => $data['referencia'] ?? null,
            'estado' => 'activo',
        ]);

        $this->storePhotos($request, $cliente);

        return $this->successResponse($cliente->fresh(), 'Cliente creado', 201);
    }

    public function show(Cliente $cliente): JsonResponse
    {
        Gate::authorize('view', $cliente);

        return $this->successResponse($cliente->load(['agencia', 'asesor', 'registradoPor']));
    }

    public function update(UpdateClienteRequest $request, Cliente $cliente): JsonResponse
    {
        Gate::authorize('update', $cliente);

        $data = Arr::except($request->validated(), ['foto_cliente', 'foto_dni', 'foto_dni_reverso', 'foto_casa', 'foto_negocio']);
        $cliente->update($data);
        $this->storePhotos($request, $cliente);

        return $this->successResponse($cliente->fresh(), 'Cliente actualizado');
    }

    public function destroy(Cliente $cliente): JsonResponse
    {
        Gate::authorize('delete', $cliente);

        $cliente->delete();

        return $this->successResponse(null, 'Cliente eliminado');
    }

    public function asignar(AsignarClienteRequest $request, Cliente $cliente): JsonResponse
    {
        $asesorId = (int) $request->validated('asesor_id');
        Gate::authorize('asignar', [$cliente, $asesorId]);

        $cliente->update(['asesor_id' => $asesorId]);

        return $this->successResponse($cliente->fresh(['asesor']), 'Cliente asignado');
    }

    private function storePhotos(StoreClienteRequest|UpdateClienteRequest $request, Cliente $cliente): void
    {
        foreach (['foto_cliente', 'foto_dni', 'foto_dni_reverso', 'foto_casa', 'foto_negocio'] as $campo) {
            if (! $request->hasFile($campo)) {
                continue;
            }

            $column = "{$campo}_path";

            if ($cliente->{$column}) {
                Storage::disk('public')->delete($cliente->{$column});
            }

            $cliente->update([$column => $request->file($campo)->store("clientes/{$cliente->id}", 'public')]);
        }
    }
}
