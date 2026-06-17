<?php

namespace App\Http\Controllers\Api\V3;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V3\LoginRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Handles authentication for the Nexum API consumed by the Chinese client frontend.
 *
 * Issues and manages JWT tokens. The guard used is 'api' (jwt driver).
 * All token operations are stateless — no session is started.
 */
class AuthController extends Controller
{
    /**
     * Authenticate a user and return a JWT token.
     *
     * @param  LoginRequest  $request  Validated credentials (email, password).
     * @return JsonResponse            Token payload or 401 on invalid credentials.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! $token = auth('api')->attempt($credentials)) {
            return response()->json(
                ['error' => 'Invalid credentials'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        return $this->tokenResponse($token);
    }

    /**
     * Return the currently authenticated user's profile.
     *
     * @return JsonResponse  User data including role.
     */
    public function me(): JsonResponse
    {
        $user = auth('api')->user();

        return response()->json([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->roles->pluck('name')->first(),
        ], Response::HTTP_OK);
    }

    /**
     * Invalidate the current JWT token and log the user out.
     *
     * @return JsonResponse  Confirmation message.
     */
    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json(
            ['message' => 'Successfully logged out'],
            Response::HTTP_OK,
        );
    }

    /**
     * Refresh the current JWT token and return a new one.
     *
     * @return JsonResponse  New token payload.
     */
    public function refresh(): JsonResponse
    {
        $token = auth('api')->refresh();

        return $this->tokenResponse($token);
    }

    /**
     * Build the standard token response payload.
     *
     * @param  string  $token  Raw JWT string.
     * @return JsonResponse
     */
    private function tokenResponse(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => auth('api')->factory()->getTTL() * 60,
        ], Response::HTTP_OK);
    }
}
