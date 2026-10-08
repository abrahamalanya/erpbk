<?php

namespace App\Modules\Facturacion\Http\Controllers;

use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Http\Requests\UpdateConfiguracionFacturacionRequest;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use App\Modules\Facturacion\Services\ConfiguracionFacturacionService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ConfiguracionFacturacionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ConfiguracionFacturacionService $configuracionService) {}

    /**
     * Devuelve la configuración de la empresa del actor. Si todavía no hay
     * ninguna, responde null para que la pantalla ofrezca crearla.
     */
    public function show(): JsonResponse
    {
        Gate::authorize('viewAny', ConfiguracionFacturacion::class);

        $actor = request()->user();

        $empresa = $actor->hasRole('sistemas')
            ? Empresa::query()->findOrFail(request()->input('empresa_id'))
            : Empresa::query()->findOrFail($actor->empresa_id);

        try {
            $configuracion = $this->configuracionService->resolverPara($empresa);
        } catch (DomainException) {
            return $this->successResponse(null, 'La empresa no tiene configuración de facturación todavía.');
        }

        return $this->successResponse($configuracion);
    }

    public function update(UpdateConfiguracionFacturacionRequest $request): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validated();

        $empresa = $actor->hasRole('sistemas')
            ? Empresa::query()->findOrFail($data['empresa_id'] ?? $actor->empresa_id)
            : Empresa::query()->findOrFail($actor->empresa_id);

        Gate::authorize('update', [ConfiguracionFacturacion::class, $empresa]);

        $configuracion = $this->configuracionService->actualizar($empresa, array_filter([
            'modo' => $data['modo'] ?? null,
            'igv_porcentaje' => $data['igv_porcentaje'] ?? null,
            'moneda' => $data['moneda'] ?? null,
            'interes_incluye_igv' => $data['interes_incluye_igv'] ?? null,
            'serie_factura_defecto' => $data['serie_factura_defecto'] ?? null,
            'serie_boleta_defecto' => $data['serie_boleta_defecto'] ?? null,
            'bloquear_si_supera_umbral' => $data['bloquear_si_supera_umbral'] ?? null,
            'activo' => $data['activo'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->successResponse($configuracion, 'Configuración actualizada');
    }
}
