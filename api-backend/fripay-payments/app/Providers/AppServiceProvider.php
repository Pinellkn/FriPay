<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->checkPhpVersion();
        $this->configureRateLimiting();
    }

    /**
     * Verifie que la version de PHP est compatible (^8.3).
     */
    private function checkPhpVersion(): void
    {
        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            abort(500, 'PHP 8.3+ requis. Version actuelle : ' . PHP_VERSION);
        }
    }

    /**
     * Configure the application's rate limiters.
     *
     * - qr-api:      60 req/min per user  (authenticated QR endpoints)
     * - qr-verify:   20 req/min per IP    (public endpoint)
     * - qr-generate: 10 req/min per user  (CPU-intensive key generation)
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('qr-api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->getKey() ?: $request->ip()
            );
        });

        RateLimiter::for('qr-verify', function (Request $request) {
            return Limit::perMinute(20)->by(
                $request->ip()
            );
        });

        RateLimiter::for('qr-generate', function (Request $request) {
            return Limit::perMinute(10)->by(
                $request->user()?->getKey() ?: $request->ip()
            );
        });

        // Webhook endpoints: 100 req/min per IP to prevent abuse
        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute(100)->by($request->ip());
        });
    }
}
