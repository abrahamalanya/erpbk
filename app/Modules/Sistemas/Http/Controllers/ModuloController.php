<?php

namespace App\Modules\Sistemas\Http\Controllers;

use App\Modules\Sistemas\Models\Modulo;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ModuloController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Modulo::class);

        return $this->successResponse(Modulo::query()->orderBy('grupo')->orderBy('nombre')->get());
    }
}
