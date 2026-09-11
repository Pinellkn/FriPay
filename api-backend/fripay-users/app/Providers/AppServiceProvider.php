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
        $this->checkPhpVersion();
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }

    /**
     * Vérifie que la version de PHP est compatible (^8.3).
     */
    private function checkPhpVersion(): void
    {
        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            abort(500, 'PHP 8.3+ requis. Version actuelle : ' . PHP_VERSION);
        }
    }
}
