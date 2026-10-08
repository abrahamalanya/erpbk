<?php

namespace App\Modules\Facturacion\Http\Controllers;

use App\Modules\Facturacion\Http\Requests\StoreSerieRequest;
use App\Modules\Facturacion\Models\SerieComprobante;
use App\Modules\Facturacion\Services\SerieService;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SerieComprobanteController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SerieService $series) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SerieComprobante::class);

        $actor = $request->user();

        $query = SerieComprobante::query()->with(['agencia']);

        if (! $actor->hasRole('sistemas')) {
            $query->where('empresa_id', $actor->empresa_id);
        }

        $query->when($request->input('tipo_documento'), fn ($q, $t) => $q->where('tipo_documento', $t))
            ->when($request->input('estado') !== null, fn ($q) => $q->where('estado', $request->boolean('estado')))
            ->orderBy('serie');

        return $this->successResponse($query->get());
    }

    public function store(StoreSerieRequest $request): JsonResponse
    {
        Gate::authorize('create', SerieComprobante::class);

        $data = $request->validated();

        $serie = $this->series->registrar(
            $request->user()->empresa_id,
            $data['agencia_id'] ?? null,
            TipoComprobante::from($data['tipo_documento']),
            $data['serie'],
            $request->user()
        );

        return $this->successResponse($serie, 'Serie registrada correctamente.', 201);
    }

    public function update(Request $request, SerieComprobante $serie): JsonResponse
    {
        Gate::authorize('update', $serie);

        $data = $request->validate([
            'descripcion' => ['nullable', 'string', 'max:255'],
            'estado' => ['sometimes', 'boolean'],
            'limite_numeracion' => ['nullable', 'integer', 'min:0'],
        ]);

        $serie->update($data);

        return $this->successResponse($serie->fresh(), 'Serie actualizada.');
    }

    public function destroy(SerieComprobante $serie): JsonResponse
    {
        Gate::authorize('delete', $serie);

        if ($serie->comprobantes()->exists()) {
            return $this->errorResponse('No se puede eliminar una serie que ya emitió comprobantes. Debe inactivarla.', 422);
        }

        $serie->delete();

        return $this->successResponse(null, 'Serie eliminada.');
    }
}
