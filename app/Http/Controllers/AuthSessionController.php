<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\JwtRefreshTokenService;
use App\Services\JwtTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuthSessionController extends Controller
{
    public function store(Request $request, JwtTokenService $tokens, JwtRefreshTokenService $refreshTokens): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::with(['roles.permissions', 'permissions', 'contact.company'])->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }

        $remember = (bool) ($credentials['remember'] ?? false);
        $refreshToken = $refreshTokens->issue($user, $remember);

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $tokens->issue($user),
            'refresh_token' => $refreshToken['token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens->expiresInSeconds(),
            'refresh_expires_in' => $refreshToken['expires_in'],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user()?->load(['roles.permissions', 'permissions', 'contact.company']);

        return response()->json([
            'user' => $user ? $this->userPayload($user) : null,
        ]);
    }

    public function destroy(Request $request, JwtTokenService $tokens, JwtRefreshTokenService $refreshTokens): JsonResponse
    {
        $revoked = $this->revokeBearerTokenIfPresent($request, $tokens);
        $refreshToken = $request->input('refresh_token');

        if (is_string($refreshToken) && $refreshToken !== '') {
            $revoked = $refreshTokens->revoke($refreshToken) || $revoked;
        }

        if (! $revoked) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Auth::forgetGuards();

        return response()->json(['message' => 'Logged out.']);
    }

    public function refresh(Request $request, JwtTokenService $tokens, JwtRefreshTokenService $refreshTokens): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $rotated = $refreshTokens->rotate($validated['refresh_token']);

        if (! $rotated) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $this->revokeBearerTokenIfPresent($request, $tokens);
        $user = $rotated['user'];

        return response()->json([
            'user' => $this->userPayload($user),
            'token' => $tokens->issue($user),
            'refresh_token' => $rotated['token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens->expiresInSeconds(),
            'refresh_expires_in' => $rotated['expires_in'],
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
        ])->save();

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $effectivePermissions = $user->roles
            ->flatMap(fn ($role) => $role->permissions)
            ->merge($user->permissions)
            ->unique('id')
            ->sortBy('name')
            ->values();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'contact_id' => $user->contact_id,
            'contact_name' => $user->contact?->name,
            'supplier_company_id' => $user->contact?->company_id,
            'supplier_company_name' => $user->contact?->company?->name,
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
            ])->values(),
            'permissions' => $effectivePermissions->map(fn ($permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'slug' => $permission->slug,
                'group' => $permission->group,
            ])->values(),
            'direct_permissions' => $user->permissions->map(fn ($permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'slug' => $permission->slug,
                'group' => $permission->group,
            ])->values(),
        ];
    }

    private function revokeBearerTokenIfPresent(Request $request, JwtTokenService $tokens): bool
    {
        $token = $request->bearerToken();

        if (! $token) {
            return false;
        }

        try {
            $payload = $tokens->decode($token);
        } catch (Throwable) {
            return false;
        }

        if ($tokens->isRevoked($payload)) {
            return false;
        }

        $tokens->revoke($payload);

        return true;
    }
}
