<?php

namespace App\Modules\Sistemas\Http\Controllers;

use App\Modules\Sistemas\Http\Requests\LoginRequest;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->errorResponse('Credenciales inválidas', 401);
        }

        if ($user->estado !== 'activo') {
            return $this->errorResponse('Usuario inactivo', 403);
        }

        $token = $user->createToken('erp-token')->plainTextToken;

        return $this->successResponse([
            'user' => $user->load('roles'),
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 'Login exitoso');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse(null, 'Logout exitoso');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->successResponse($request->user()->load('roles'));
    }
}
