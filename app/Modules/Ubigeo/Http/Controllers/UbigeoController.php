<?php

namespace App\Modules\Ubigeo\Http\Controllers;

use App\Modules\Ubigeo\Models\UbigeoDepartamento;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Modules\Ubigeo\Models\UbigeoProvincia;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de ubigeo (INEI) para los selects en cascada de Cliente/Inmueble
 * — solo lectura, sin autorización especial (no expone nada sensible).
 */
class UbigeoController extends Controller
{
    use ApiResponse;

    public function departamentos(): JsonResponse
    {
        return $this->successResponse(
            UbigeoDepartamento::query()->orderBy('nombre')->get(['id', 'codigo', 'nombre'])
        );
    }

    public function provincias(UbigeoDepartamento $departamento): JsonResponse
    {
        return $this->successResponse(
            $departamento->provincias()->get(['id', 'ubigeo_departamento_id', 'codigo', 'nombre'])
        );
    }

    public function distritos(UbigeoProvincia $provincia): JsonResponse
    {
        return $this->successResponse(
            $provincia->distritos()->get(['id', 'ubigeo_provincia_id', 'codigo', 'nombre'])
        );
    }

    /**
     * Resuelve el árbol completo (distrito -> provincia -> departamento) de
     * un distrito — usado para precargar el select en cascada al editar un
     * registro que ya tiene un ubigeo_distrito_id, sin que el frontend tenga
     * que encadenar tres requests para saber qué departamento/provincia
     * seleccionar primero.
     */
    public function distrito(UbigeoDistrito $distrito): JsonResponse
    {
        return $this->successResponse($distrito->load('provincia.departamento'));
    }
}
