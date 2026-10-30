<?php

namespace App\Modules\Sistemas\Http\Controllers;

use App\Modules\Sistemas\Http\Requests\LoginRequest;
use App\Modules\Sistemas\Services\UserPresenterService;
use App\Modules\Ubicacion\Events\UbicacionAsesorActualizada;
use App\Modules\Ubicacion\Models\DispositivoGps;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Ubicacion\Services\DispositivoGpsService;
use App\Modules\Usuario\Models\Impersonacion;
use App\Modules\Usuario\Models\User;
use App\Modules\Usuario\Services\ImpersonacionService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly UserPresenterService $presenter,
        private readonly ImpersonacionService $impersonaciones,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $field = filter_var($request->email, FILTER_VALIDATE_EMAIL) ? 'email' : 'dni';

        $user = User::where($field, $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Credenciales inválidas', 401);
        }

        if ($user->estado !== 'activo') {
            return $this->errorResponse('Usuario inactivo', 403);
        }

        $nuevoToken = $user->createToken('erp-token');
        $token = $nuevoToken->plainTextToken;
        if ($request->filled('dispositivo_id')) {
            app(DispositivoGpsService::class)->vincular($user, $request->string('dispositivo_id')->toString(), $nuevoToken->accessToken->id);
        }

        return $this->successResponse([
            'user' => $this->presenter->decorar($user),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 'Login exitoso');
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        DispositivoGps::where('token_id', $token->id)->update(['token_id' => null]);
        $impersonacion = $this->impersonaciones->activaParaToken($token->id ?? null);

        if ($impersonacion !== null) {
            // El admin salió por "logout" en vez de "volver a mi cuenta": de
            // todas formas hay que cerrar la bitácora con finalizado_at, o
            // quedaría una fila "activa" para siempre.
            $this->impersonaciones->finalizar($impersonacion);
        } else {
            $token->delete();
        }

        foreach (UbicacionAsesor::where('user_id', $request->user()->id)->whereHas('dispositivo', fn ($q) => $q->whereNull('token_id'))->get() as $ubicacion) {
            event(new UbicacionAsesorActualizada($ubicacion, $request->user()));
        }

        return $this->successResponse(null, 'Logout exitoso');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->presenter->decorar($request->user());
        $impersonacion = $this->impersonaciones->activaParaToken($request->user()->currentAccessToken()->id ?? null);

        $user->setAttribute('impersonacion', $this->describirImpersonacion($impersonacion));

        return $this->successResponse($user);
    }

    /**
     * @return array{activa: true, actor_real: array{id: int, nombre: string, apellido: string}, desde: Carbon}|null
     */
    private function describirImpersonacion(?Impersonacion $impersonacion): ?array
    {
        if ($impersonacion === null) {
            return null;
        }

        $actor = $impersonacion->iniciadoPor;

        return [
            'activa' => true,
            'actor_real' => [
                'id' => $actor->id,
                'nombre' => $actor->nombre,
                'apellido' => $actor->apellido,
            ],
            'desde' => $impersonacion->created_at,
        ];
    }
}
