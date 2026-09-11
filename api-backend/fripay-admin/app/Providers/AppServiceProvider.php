<?php

namespace App\Providers;

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
}
