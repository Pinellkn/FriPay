<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Ce fichier configure le middleware HandleCors (inclus globalement
    | dans Laravel 11+). Les valeurs correspondent au gateway FriPay.
    |
    */

    // Routes concernées par CORS
    'paths' => ['api/*', 'up'],

    // Méthodes HTTP autorisées
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    // Origines autorisées (en dev : *, en prod : liste explicite)
    'allowed_origins' => ['*'],

    // Patterns regex pour les origines (ex: subdomaines)
    'allowed_origins_patterns' => [],

    // En-têtes autorisés côté client
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'Idempotency-Key',
        'X-Request-Id',
        'Accept-Language',
    ],

    // En-têtes exposés au navigateur
    'exposed_headers' => [
        'X-Request-Id',
    ],

    // Cache du preflight OPTIONS (secondes)
    'max_age' => 86400,

    // Envoyer les credentials (cookies, Authorization header)
    'supports_credentials' => false,

];
