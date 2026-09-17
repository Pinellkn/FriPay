<?php

/**
 * ============================================================================
 *  FriPay API Gateway v2
 *  Point d'entrée unique pour tous les microservices FriPay.
 *
 *  Fonctionnalités :
 *  - Routage intelligent vers Users / Payments / Admin
 *  - Rate limiting (token bucket) par IP
 *  - Circuit breaker (protection des services aval)
 *  - Logging structuré (JSON) avec rotation automatique
 *  - Gestion des erreurs normalisée (RFC 7807)
 *  - Forward CORS, Authorization, Idempotency-Key, X-Request-Id
 *  - Endpoint de monitoring interne : GET /__gateway/status (IP whitelist)
 * ============================================================================
 */

// --- Autoload PSR-4-like (classes dans src/) ---
spl_autoload_register(function (string $class) {
    $file = __DIR__ . '/../src/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// --- Load .env if present (dotenv-lite pour le gateway) ---
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, ' \"\'');
        if (!array_key_exists($key, $_ENV) && !array_key_exists($key, $_SERVER)) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// --- env() helper (compatible Laravel) ---
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
}

// --- Configuration ---
$config = require __DIR__ . '/../config/gateway.php';

// --- Initialisation des composants ---
$logger         = new LogManager($config['logging']);
$storageConfig  = $config['storage'] ?? ['driver' => 'file'];
$rateLimiter    = new RateLimiter($config['rate_limiting'], $storageConfig);
$circuitBreaker = new CircuitBreaker($config['circuit_breaker'], $storageConfig);

// --- Identification du client ---
$clientIp   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$requestId  = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path       = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// --- CORS Preflight ---
if ($method === 'OPTIONS') {
    sendCorsHeaders($config['cors'], $requestId);
    http_response_code(204);
    $logger->info("CORS preflight {$path}");
    exit;
}

// --- Endpoint de monitoring interne (IP whitelist) ---
if ($path === '/__gateway/status' && $method === 'GET') {
    $allowedIps = $config['monitoring']['allowed_ips'] ?? ['127.0.0.1', '::1'];
    $debugKey   = $config['monitoring']['debug_key'] ?? null;
    $providedKey = $_SERVER['HTTP_X_FRIPAY_DEBUG_KEY'] ?? '';
    $hasValidDebugKey = $debugKey && hash_equals($debugKey, $providedKey);

    if (!in_array($clientIp, $allowedIps, true) && !$hasValidDebugKey) {
        sendCorsHeaders($config['cors'], $requestId);
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'type'       => 'FORBIDDEN',
            'title'      => 'Accès interdit',
            'status'     => 403,
            'detail'     => 'Endpoint réservé à l\'administration interne.',
            'request_id' => $requestId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    sendCorsHeaders($config['cors'], $requestId);
    header('Content-Type: application/json; charset=utf-8');

    $status = [
        'gateway'    => 'FriPay API Gateway v2',
        'request_id' => $requestId,
        'request_time' => gmdate('Y-m-d\TH:i:s\Z'),
        'services'   => [],
    ];

    // État de chaque service
    foreach ($config['services'] as $key => $service) {
        $cbState = $circuitBreaker->getState($key);
        $status['services'][$key] = [
            'name'     => $service['name'],
            'base_url' => $service['base_url'],
            'circuit'  => $cbState['state'],
            'failures' => $cbState['failures'],
        ];
    }

    echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// --- Rate Limiting ---
if ($config['rate_limiting']['enabled']) {
    $rateCheck = $rateLimiter->allow($clientIp);
    header('X-RateLimit-Limit: ' . $rateCheck['limit']);
    header('X-RateLimit-Remaining: ' . $rateCheck['remaining']);
    header('X-RateLimit-Reset: ' . (time() + $rateCheck['retry_after']));

    if (!$rateCheck['allowed']) {
        $logger->warning("Rate limit exceeded for {$clientIp}", [
            'remaining'  => $rateCheck['remaining'],
            'retry_after' => $rateCheck['retry_after'],
        ]);

        sendCorsHeaders($config['cors'], $requestId);
        http_response_code(429);
        header('Retry-After: ' . $rateCheck['retry_after']);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'type'       => 'RATE_LIMIT_EXCEEDED',
            'title'      => 'Trop de requêtes',
            'status'     => 429,
            'detail'     => 'Vous avez dépassé la limite de requêtes. Réessayez dans ' . $rateCheck['retry_after'] . ' secondes.',
            'request_id' => $requestId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// --- Routage ---
$serviceKey = resolveServiceKey($path, $config['services']);

if (!$serviceKey || !isset($config['services'][$serviceKey])) {
    $logger->warning("Route not found: {$path}");

    sendCorsHeaders($config['cors'], $requestId);
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'type'       => 'NOT_FOUND',
        'title'      => 'Route non trouvée',
        'status'     => 404,
        'detail'     => "Aucune route ne correspond à : {$path}",
        'request_id' => $requestId,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetService = $config['services'][$serviceKey];

// --- Forward de la requête vers le service cible ---
$proxyClient = new ProxyClient($serviceKey, $targetService, $circuitBreaker, $logger);

// Collecter les headers de la requête entrante
$incomingHeaders = [];
foreach (getallheaders() as $key => $value) {
    if (in_array(strtolower($key), ['host', 'connection', 'transfer-encoding'])) {
        continue;
    }
    $incomingHeaders[$key] = $value;
}

// Garantir un X-Request-Id
$incomingHeaders['X-Request-Id'] = $requestId;

$requestBody = file_get_contents('php://input');

$proxyResponse = $proxyClient->forward($method, $path, $incomingHeaders, $requestBody);

// --- Envoyer la réponse au client ---
sendCorsHeaders($config['cors'], $requestId);

http_response_code($proxyResponse['status']);

// Forwarder les headers de réponse (filtrer les en-têtes indésirables)
$skipHeaders = ['transfer-encoding', 'content-encoding', 'connection'];
foreach ($proxyResponse['headers'] as $headerLine) {
    $headerName = strtolower(explode(':', $headerLine, 2)[0] ?? '');
    if (!in_array($headerName, $skipHeaders) && $headerLine !== '') {
        header($headerLine, false);
    }
}

// Garantir un body de réponse
$responseBody = $proxyResponse['body'];
if (empty($responseBody)) {
    if ($proxyResponse['status'] >= 400) {
        $responseBody = json_encode([
            'type'       => 'PROXY_ERROR',
            'title'      => 'Erreur du service distant',
            'status'     => $proxyResponse['status'],
            'detail'     => "Le service cible a retourné une erreur (HTTP {$proxyResponse['status']}) sans message détaillé.",
            'request_id' => $requestId,
        ], JSON_UNESCAPED_UNICODE);
    } elseif ($proxyResponse['status'] === 204) {
        // Pas de body pour 204 No Content
    } else {
        $responseBody = '{}';
    }
}

echo $responseBody;

// Nettoyer les entrées expirées du rate limiter (~1% des requêtes)
if (mt_rand(1, 100) === 1) {
    $rateLimiter->cleanExpired();
}

// ========================================================================= //
//  Fonctions utilitaires
// ========================================================================= //

/**
 * Résout la clé du service cible en fonction du chemin.
 * Retourne la clé (ex: 'users', 'payments', 'admin') ou null.
 */
function resolveServiceKey(string $path, array $services): ?string
{
    foreach ($services as $key => $service) {
        foreach ($service['routes'] as $route) {
            if (str_starts_with($path, $route)) {
                return $key;
            }
        }
    }
    return null;
}

/**
 * Envoie les headers CORS.
 *
 * Access-Control-Allow-Origin accepte un seul origine ou '*'.
 * Si FRIPAY_CORS_ORIGINS contient plusieurs origines, on v�rifie
 * que la requ�te provient d'une origine autoris�e et on renvoie
 * cette origine sp�cifique.
 */
function sendCorsHeaders(array $corsConfig, string $requestId): void
{
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = $corsConfig['allowed_origins'];

    // Si '*' est dans la liste, on l'utilise directement
    if (in_array('*', $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: *');
    } elseif (!empty($requestOrigin) && in_array($requestOrigin, $allowedOrigins, true)) {
        // Repondre avec l'origine specifique du client (pas la liste entiere)
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Vary: Origin');
    } elseif (count($allowedOrigins) === 1) {
        // Une seule origine autorisee
        header('Access-Control-Allow-Origin: ' . reset($allowedOrigins));
    }
    // Si pas d Origin header (requete serveur-a-serveur) : pas de CORS headers car les navigateurs nenvoient Origin que pour les requetes cross-origin.

    header('Access-Control-Allow-Methods: ' . implode(', ', $corsConfig['allowed_methods']));
    header('Access-Control-Allow-Headers: ' . implode(', ', $corsConfig['allowed_headers']));
    header('Access-Control-Max-Age: ' . $corsConfig['max_age']);
    header('X-FriPay-Gateway: v2');
    header('X-Request-Id: ' . $requestId);
}
