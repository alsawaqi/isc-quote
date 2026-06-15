<?php

namespace App\Services;

use App\Models\RevokedJwtToken;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

class JwtTokenService
{
    public function issue(User $user): string
    {
        $now = Carbon::now();
        $expiresAt = $now->copy()->addSeconds($this->expiresInSeconds());

        return JWT::encode([
            'iss' => config('jwt.issuer'),
            'jti' => (string) Str::uuid(),
            'typ' => 'access',
            'sub' => (string) $user->getKey(),
            'iat' => $now->timestamp,
            'nbf' => $now->timestamp,
            'exp' => $expiresAt->timestamp,
            'roles' => $user->roles->pluck('slug')->values()->all(),
        ], $this->secret(), 'HS256');
    }

    public function decode(string $token): stdClass
    {
        return JWT::decode($token, new Key($this->secret(), 'HS256'));
    }

    public function isRevoked(stdClass $payload): bool
    {
        if (($payload->typ ?? null) !== 'access') {
            return true;
        }

        $jti = $this->jti($payload);

        if ($jti === null) {
            return true;
        }

        return RevokedJwtToken::query()
            ->where('jti', $jti)
            ->where('expires_at', '>', Carbon::now())
            ->exists();
    }

    public function revoke(stdClass $payload): void
    {
        $jti = $this->jti($payload);

        if ($jti === null) {
            return;
        }

        RevokedJwtToken::query()->updateOrCreate(
            ['jti' => $jti],
            [
                'user_id' => isset($payload->sub) ? (int) $payload->sub : null,
                'expires_at' => Carbon::createFromTimestamp((int) ($payload->exp ?? Carbon::now()->timestamp)),
                'revoked_at' => Carbon::now(),
            ]
        );
    }

    public function expiresInSeconds(): int
    {
        return max(1, (int) config('jwt.ttl', 120)) * 60;
    }

    private function jti(stdClass $payload): ?string
    {
        $jti = $payload->jti ?? null;

        return is_string($jti) && $jti !== '' ? $jti : null;
    }

    private function secret(): string
    {
        $secret = (string) config('jwt.secret');

        if ($secret === '') {
            throw new RuntimeException('JWT secret is not configured.');
        }

        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $secret;
    }
}
