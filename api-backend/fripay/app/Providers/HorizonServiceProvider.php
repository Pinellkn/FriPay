<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     * Dashboard accessible : (a) en local ; (b) aux emails listés dans la
     * variable HORIZON_ALLOWED_EMAILS (séparés par des virgules). Le
     * dashboard expose les payloads de jobs — ne JAMAIS l'ouvrir publiquement.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if (app()->environment('local')) {
                return true;
            }

            $allowed = array_filter(array_map(
                'trim',
                explode(',', (string) config('fripay.horizon.allowed_emails', ''))
            ));

            return $user !== null && in_array($user->email, $allowed, true);
        });
    }
}
