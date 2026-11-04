<?php

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Http\Requests\LoginRequest;
use App\Modules\Users\Http\Requests\RegisterRequest;
use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Users\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Issues, refreshes and revokes the short-lived JWT bearer tokens every
 * client authenticates with.
 */
final class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'username' => $request->validated('username'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        return response()->json([
            'message' => 'Account created successfully.',
            ...$this->signIn($user),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return response()->json([
            'message' => 'Signed in successfully.',
            ...$this->signIn($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => (new UserResource($request->user()))->resolve(),
        ]);
    }

    /**
     * Blacklist the current token so it cannot be used again before it expires.
     */
    public function logout(): JsonResponse
    {
        Auth::guard('api')->logout();

        return response()->json(['message' => 'Signed out successfully.']);
    }

    /**
     * Exchange a valid or recently expired token for a new one. The old token
     * is blacklisted.
     */
    public function refresh(): JsonResponse
    {
        return response()->json([
            'message' => 'Token refreshed.',
            ...$this->tokenPayload(Auth::guard('api')->refresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function signIn(User $user): array
    {
        return [
            ...$this->tokenPayload(Auth::guard('api')->login($user)),
            'data' => (new UserResource($user))->resolve(),
            'redirect_to' => $user->homeUrl(),
        ];
    }

    /**
     * @return array{token: string, token_type: string, expires_in: int}
     */
    private function tokenPayload(string $token): array
    {
        return [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
        ];
    }
}
