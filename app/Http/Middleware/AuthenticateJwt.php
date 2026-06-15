<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateJwt
{
    public function __construct(private readonly JwtTokenService $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        try {
            $payload = $this->tokens->decode($token);
        } catch (Throwable) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($this->tokens->isRevoked($payload)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = User::with('roles')->find((int) ($payload->sub ?? 0));

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('jwt_payload', $payload);

        return $next($request);
    }
}
