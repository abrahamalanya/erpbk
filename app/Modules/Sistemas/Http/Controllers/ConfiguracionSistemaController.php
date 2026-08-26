<?php

namespace App\Modules\Sistemas\Http\Controllers;

use App\Modules\Sistemas\Http\Requests\UpdateConfiguracionSistemaRequest;
use App\Modules\Sistemas\Models\ConfiguracionSistema;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ConfiguracionSistemaController extends Controller
{
    use ApiResponse;

    /**
     * Public: the login page (unauthenticated) needs the app name/favicon
     * before anyone signs in, so this endpoint has no Gate check.
     */
    public function show(): JsonResponse
    {
        return $this->successResponse(ConfiguracionSistema::actual());
    }

    public function update(UpdateConfiguracionSistemaRequest $request): JsonResponse
    {
        Gate::authorize('update', ConfiguracionSistema::class);

        $configuracion = ConfiguracionSistema::actual();
        $data = $request->safe()->except('favicon');

        if (! empty($data['nombre_app'])) {
            $configuracion->nombre_app = $data['nombre_app'];
        }

        if ($request->hasFile('favicon')) {
            if ($configuracion->favicon_path) {
                Storage::disk('public')->delete($configuracion->favicon_path);
            }

            $configuracion->favicon_path = $request->file('favicon')->store('sistemas/favicon', 'public');
        }

        $configuracion->save();

        return $this->successResponse($configuracion->fresh(), 'Configuración actualizada');
    }
}
