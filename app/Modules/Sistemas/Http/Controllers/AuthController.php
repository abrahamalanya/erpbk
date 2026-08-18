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
            'user' => $this->withPermissions($user),
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
        return $this->successResponse($this->withPermissions($request->user()));
    }

    /**
     * Attaches the user's effective permission names (via their roles) to
     * the response, so the frontend can gate UI by permission instead of
     * hardcoding role names.
     *
     * Named "permission_names" (not "permissions") because Spatie's
     * HasRoles trait already defines a "permissions" relationship (direct,
     * non-role permissions) on User — reusing that key would silently be
     * shadowed by the (empty, in this app) relation during serialization.
     */
    private function withPermissions(User $user): User
    {
        $user->load('roles');
        $user->setAttribute('permission_names', $user->getAllPermissions()->pluck('name')->values());

        return $user;
    }
}
