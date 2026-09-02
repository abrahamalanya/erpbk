<?php

namespace App\Modules\Credito\Http\Controllers;

use App\Modules\Credito\Http\Requests\UpdateConfiguracionCreditoRequest;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Services\ConfiguracionCreditoService;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ConfiguracionCreditoController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ConfiguracionCreditoService $configuracionService) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', ConfiguracionCredito::class);

        $actor = request()->user();
        $query = ConfiguracionCredito::query()->with(['empresa', 'agencia']);

        if (! $actor->hasRole('sistemas')) {
            $query->where('empresa_id', $actor->empresa_id);
        }

        if ($actor->hasRole('administrador_agencia')) {
            $query->where('agencia_id', $actor->agencia_id);
        }

        return $this->successResponse($query->get());
    }

    public function update(UpdateConfiguracionCreditoRequest $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validated();

        $empresa = $actor->hasRole('sistemas')
            ? Empresa::query()->findOrFail($data['empresa_id'])
            : Empresa::query()->findOrFail($actor->empresa_id);

        $agencia = isset($data['agencia_id']) ? Agencia::query()->findOrFail($data['agencia_id']) : null;

        Gate::authorize('update', [ConfiguracionCredito::class, $agencia]);

        $configuracion = $this->configuracionService->actualizar($empresa, $agencia, [
            'interes_default' => $data['interes_default'],
            'plazo_dias' => $data['plazo_dias'],
            'dias_espera_mora' => $data['dias_espera_mora'],
            'dias_minimo_interes' => $data['dias_minimo_interes'],
            'tasa_mora_diaria' => $data['tasa_mora_diaria'],
            'max_refrendos' => $data['max_refrendos'] ?? null,
        ], $data['tipo_credito'] ?? 'prendario');

        return $this->successResponse($configuracion, 'Configuración actualizada');
    }
}
