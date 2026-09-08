<?php

namespace Modules\Identity\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Modules\Identity\Infrastructure\ReadModels\User;
use Modules\Identity\Presentation\Http\Resources\UserResource;

class AuthController
{
    /**
     * POST /auth/login — sem middleware auth:sanctum (ver routes.php).
     * Ability única "web" (decidido na F3 — sem papéis/permissões na v1).
     *
     * Login/logout não são comandos de domínio: consultam o read model e emitem/revogam um
     * token Sanctum diretamente, sem passar pelo UserAggregate.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Credenciais inválidas.',
            ], 401);
        }

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * POST /auth/logout — revoga apenas o token corrente, não todos os tokens do usuário.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * GET /auth/me
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()),
        ]);
    }
}
