<?php

/**
 * Configuration de l'API Gateway FriPay
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Services en amont
    |--------------------------------------------------------------------------
    | Chaque service correspond à un microservice FriPay.
    | La clé est utilisée pour le routage, les logs et le circuit breaker.
    */
    'services' => [
        'users' => [
            'name'         => 'Users Service',
            'base_url'     => 'http://127.0.0.1:8000',
            'routes'       => ['/api/v1/auth/', '/api/v1/users/', '/api/v1/notifications'],
            'timeout'      => 20,
            'health_check' => '/up',
        ],
        'payments' => [
            'name'         => 'Payments Service',
            'base_url'     => 'http://127.0.0.1:8001',
            'routes'       => ['/api/v1/transfers', '/api/v1/webhooks', '/api/v1/webhooks/', '/api/v1/qr/', '/api/v1/wallet', '/api/v1/complaints', '/api/v1/bills'],
            'timeout'      => 30,
            'health_check' => '/up',
        ],
        'admin' => [
            'name'         => 'Admin Service',
            'base_url'     => 'http://127.0.0.1:8002',
            'routes'       => ['/api/v1/admin'],
            'timeout'      => 15,
            'health_check' => '/up',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stockage (Redis ou fichier)
    |--------------------------------------------------------------------------
    | driver : 'file' (défaut) ou 'redis'
    | En multi-instance, utiliser 'redis' pour partager l'état entre instances.
    */
    'storage' => [
        'driver' => env('GATEWAY_STORAGE_DRIVER', 'file'),
        'redis' => [
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'port'     => (int) env('REDIS_PORT', 6379),
            'password' => env('REDIS_PASSWORD', null),
            'database' => (int) env('REDIS_DB', 0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    | Limite le nombre de requêtes par adresse IP.
    | bucket_size : nombre de tokens maximum (rafale autorisée)
    | refill_rate : tokens ajoutés par seconde
    */
    'rate_limiting' => [
        'enabled'      => true,
        'bucket_size'  => 60,       // 60 requêtes max en rafale
        'refill_rate'  => 1,        // 1 token / seconde → 60 req/min en steady
        'storage_path' => __DIR__ . '/../storage/rate-limiter',
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    | Détecte les défaillances d'un service amont et arrête de lui envoyer
    | du trafic pendant un certain temps.
    */
    'circuit_breaker' => [
        'enabled'              => true,
        'failure_threshold'    => 5,   // échecs consécutifs avant ouverture
        'success_threshold'    => 3,   // succès consécutifs avant fermeture (half-open)
        'open_timeout_seconds' => 30,  // temps en état "open" avant half-open
        'storage_path'         => __DIR__ . '/../storage/circuit-breaker',
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring
    |--------------------------------------------------------------------------
    | Accès restreint aux IP internes pour l'endpoint /__gateway/status
    */
    'monitoring' => [
        'allowed_ips' => ['127.0.0.1', '::1'],
        // §8 - clé partagée pour que l'app mobile (interface technique) lise
        // le statut du gateway sans être sur l'IP whitelistée.
        'debug_key' => env('FRIPAY_GATEWAY_DEBUG_KEY', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled'    => true,
        'log_path'   => __DIR__ . '/../storage/logs',
        'log_rotate' => 7, // jours de rétention
    ],

    /*
    |--------------------------------------------------------------------------
    | CORS
    |--------------------------------------------------------------------------
    */
    'cors' => [
        'allowed_origins' => array_filter(explode(',', env('FRIPAY_CORS_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000'))),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => [
            'Content-Type', 'Authorization', 'Idempotency-Key',
            'X-Request-Id', 'Accept-Language', 'X-Signature',
            'X-Fripay-Debug-Key',
        ],
        'max_age' => 86400,
    ],
];
