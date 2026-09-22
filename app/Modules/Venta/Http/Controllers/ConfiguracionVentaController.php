<?php

namespace App\Modules\Venta\Http\Controllers;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Venta\Http\Requests\UpdateConfiguracionVentaRequest;
use App\Modules\Venta\Models\ConfiguracionVenta;
use App\Modules\Venta\Services\ConfiguracionVentaService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ConfiguracionVentaController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ConfiguracionVentaService $configuracionService) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', ConfiguracionVenta::class);

        $actor = request()->user();
        $query = ConfiguracionVenta::query()->with(['empresa', 'agencia']);

        if (! $actor->hasRole('sistemas')) {
            $query->where('empresa_id', $actor->empresa_id);
        }

        if ($actor->hasRole('administrador_agencia')) {
            $query->where('agencia_id', $actor->agencia_id);
        }

        return $this->successResponse($query->get());
    }

    public function update(UpdateConfiguracionVentaRequest $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validated();

        $empresa = $actor->hasRole('sistemas')
            ? Empresa::query()->findOrFail($data['empresa_id'])
            : Empresa::query()->findOrFail($actor->empresa_id);

        $agencia = isset($data['agencia_id']) ? Agencia::query()->findOrFail($data['agencia_id']) : null;

        Gate::authorize('update', [ConfiguracionVenta::class, $agencia]);

        $configuracion = $this->configuracionService->actualizar($empresa, $agencia, [
            'interes_mensual_default' => $data['interes_mensual_default'],
        ]);

        return $this->successResponse($configuracion, 'Configuración actualizada');
    }

    public function destroy(ConfiguracionVenta $configuracion): JsonResponse
    {
        Gate::authorize('delete', [ConfiguracionVenta::class, $configuracion->agencia]);

        $this->configuracionService->eliminar($configuracion);

        return $this->successResponse(null, 'Configuración eliminada');
    }
}
