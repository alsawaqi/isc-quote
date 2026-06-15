<?php

namespace App\Services;

use App\Models\JwtRefreshToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class JwtRefreshTokenService
{
    /**
     * @return array{token: string, expires_in: int}
     */
    public function issue(User $user, bool $remember): array
    {
        $plainToken = Str::random(80);
        $expiresIn = $this->expiresInSeconds($remember);

        JwtRefreshToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $this->hash($plainToken),
            'remember' => $remember,
            'expires_at' => Carbon::now()->addSeconds($expiresIn),
        ]);

        return [
            'token' => $plainToken,
            'expires_in' => $expiresIn,
        ];
    }

    /**
     * @return array{user: User, token: string, expires_in: int}|null
     */
    public function rotate(string $plainToken): ?array
    {
        $token = $this->activeToken($plainToken);

        if (! $token) {
            return null;
        }

        $token->forceFill([
            'last_used_at' => Carbon::now(),
            'revoked_at' => Carbon::now(),
        ])->save();

        $user = User::with(['roles.permissions', 'permissions', 'contact.company'])->find($token->user_id);

        if (! $user) {
            return null;
        }

        $issued = $this->issue($user, (bool) $token->remember);

        return [
            'user' => $user,
            'token' => $issued['token'],
            'expires_in' => $issued['expires_in'],
        ];
    }

    public function revoke(string $plainToken): bool
    {
        $token = JwtRefreshToken::query()
            ->where('token_hash', $this->hash($plainToken))
            ->whereNull('revoked_at')
            ->first();

        if (! $token) {
            return false;
        }

        $token->forceFill(['revoked_at' => Carbon::now()])->save();

        return true;
    }

    public function expiresInSeconds(bool $remember): int
    {
        $minutes = $remember
            ? (int) config('jwt.remember_refresh_ttl', 43200)
            : (int) config('jwt.refresh_ttl', 1440);

        return max(1, $minutes) * 60;
    }

    private function activeToken(string $plainToken): ?JwtRefreshToken
    {
        return JwtRefreshToken::query()
            ->where('token_hash', $this->hash($plainToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();
    }

    private function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
