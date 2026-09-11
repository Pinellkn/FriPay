<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Http\Request;

/**
 * Authenticate middleware personnalisée pour API-only service.
 *
 * Empêche la redirection vers route('login') qui n'existe pas
 * dans un service API. Retourne toujours un JSON 401.
 */
class Authenticate extends BaseAuthenticate
{
    /**
     * Désactiver toute redirection — API-only, jamais de redirect HTML.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
