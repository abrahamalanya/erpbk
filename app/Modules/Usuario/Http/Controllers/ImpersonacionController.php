<?php

namespace App\Modules\Usuario\Http\Controllers;

use App\Modules\Sistemas\Services\UserPresenterService;
use App\Modules\Usuario\Http\Requests\IniciarImpersonacionRequest;
use App\Modules\Usuario\Models\User;
use App\Modules\Usuario\Services\ImpersonacionService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ImpersonacionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ImpersonacionService $service,
        private readonly UserPresenterService $presenter,
    ) {}

    public function iniciar(IniciarImpersonacionRequest $request, User $user): JsonResponse
    {
        Gate::authorize('impersonar', $user);

        $resultado = $this->service->iniciar(
            $request->user(),
            $user,
            $request->validated('motivo'),
            (string) $request->ip(),
            (string) $request->userAgent(),
        );

        return $this->successResponse([
            'usuario' => $this->presenter->decorar($user),
            'access_token' => $resultado['token'],
            'token_type' => 'Bearer',
            'impersonacion' => [
                'id' => $resultado['impersonacion']->id,
                'desde' => $resultado['impersonacion']->created_at,
            ],
        ], 'Ingresaste como '.$user->nombre.' '.$user->apellido);
    }

    public function finalizar(Request $request): JsonResponse
    {
        $impersonacion = $this->service->activaParaToken($request->user()->currentAccessToken()->id ?? null);

        if ($impersonacion === null) {
            return $this->errorResponse('No hay una sesión de impersonación activa', 404);
        }

        $this->service->finalizar($impersonacion);

        return $this->successResponse(null, 'Impersonación finalizada');
    }
}
