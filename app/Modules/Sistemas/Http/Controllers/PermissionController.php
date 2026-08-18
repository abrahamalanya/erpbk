<?php

namespace App\Modules\Sistemas\Http\Controllers;

use App\Modules\Sistemas\Models\Permission;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PermissionController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Permission::class);

        return $this->successResponse(Permission::query()->get());
    }
}
