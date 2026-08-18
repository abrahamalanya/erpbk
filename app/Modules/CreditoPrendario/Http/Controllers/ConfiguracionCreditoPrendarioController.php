<?php

namespace App\Modules\CreditoPrendario\Http\Controllers;

use App\Modules\CreditoPrendario\Http\Requests\UpdateConfiguracionCreditoPrendarioRequest;
use App\Modules\CreditoPrendario\Models\ConfiguracionCreditoPrendario;
use App\Modules\CreditoPrendario\Services\ConfiguracionCreditoPrendarioService;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ConfiguracionCreditoPrendarioController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ConfiguracionCreditoPrendarioService $configuracionService) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', ConfiguracionCreditoPrendario::class);

        $actor = request()->user();
        $query = ConfiguracionCreditoPrendario::query()->with(['empresa', 'agencia']);

        if (! $actor->hasRole('sistemas')) {
            $query->where('empresa_id', $actor->empresa_id);
        }

        if ($actor->hasRole('administrador_agencia')) {
            $query->where('agencia_id', $actor->agencia_id);
        }

        return $this->successResponse($query->get());
    }

    public function update(UpdateConfiguracionCreditoPrendarioRequest $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validated();

        $empresa = $actor->hasRole('sistemas')
            ? Empresa::query()->findOrFail($data['empresa_id'])
            : Empresa::query()->findOrFail($actor->empresa_id);

        $agencia = isset($data['agencia_id']) ? Agencia::query()->findOrFail($data['agencia_id']) : null;

        Gate::authorize('update', [ConfiguracionCreditoPrendario::class, $agencia]);

        $configuracion = $this->configuracionService->actualizar($empresa, $agencia, $data['tipo'], [
            'interes_default' => $data['interes_default'],
            'plazo_dias' => $data['plazo_dias'],
            'dias_espera_mora' => $data['dias_espera_mora'],
            'tasa_mora_diaria' => $data['tasa_mora_diaria'],
            'max_refrendos' => $data['max_refrendos'] ?? null,
        ]);

        return $this->successResponse($configuracion, 'Configuración actualizada');
    }
}
