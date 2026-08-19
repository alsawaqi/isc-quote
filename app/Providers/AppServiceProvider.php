<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('token-refresh', function (Request $request): array {
            $refreshTokenHash = hash('sha256', (string) $request->input('refresh_token'));

            return [
                Limit::perMinute(10)->by('refresh-token|'.$refreshTokenHash.'|'.$request->ip()),
                Limit::perMinute(60)->by('refresh-ip|'.$request->ip()),
            ];
        });
    }
}
