<?php

/**
 * Proxy Client
 *
 * Envoie une requête HTTP vers un microservice cible en
 * transférant les headers, la méthode et le body.
 *
 * S'interface avec le CircuitBreaker et normalise les réponses.
 */
class ProxyClient
{
    private array $serviceConfig;
    private string $serviceKey;
    private CircuitBreaker $circuitBreaker;
    private LogManager $logger;

    public function __construct(string $serviceKey, array $serviceConfig, CircuitBreaker $circuitBreaker, LogManager $logger)
    {
        $this->serviceKey     = $serviceKey;
        $this->serviceConfig  = $serviceConfig;
        $this->circuitBreaker = $circuitBreaker;
        $this->logger         = $logger;
    }

    /**
     * Exécute la requête proxy.
     *
     * @return array { 'status' => int, 'body' => string, 'headers' => array }
     */
    public function forward(string $method, string $path, array $headers, string $body): array
    {
        $serviceKey = $this->serviceKey;
        $targetUrl  = rtrim($this->serviceConfig['base_url'], '/') . '/' . ltrim($path, '/');

        // Vérifier le circuit breaker
        if (!$this->circuitBreaker->isAvailable($serviceKey)) {
            $cbState = $this->circuitBreaker->getState($serviceKey);
            $this->logger->warning("Circuit breaker OPEN for {$serviceKey}", [
                'remaining_retry' => $cbState['remaining_retry'],
            ]);

            return [
                'status' => 503,
                'body'   => json_encode([
                    'type'   => 'SERVICE_UNAVAILABLE',
                    'title'  => 'Service temporairement indisponible',
                    'status' => 503,
                    'detail' => "Le service {$this->serviceConfig['name']} est momentanément inaccessible. Réessayez dans {$cbState['remaining_retry']} secondes.",
                ]),
                'headers' => ['Content-Type: application/json; charset=utf-8'],
            ];
        }

        // Construire les headers pour cURL
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "$key: $value";
        }

        $ch = curl_init($targetUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_HTTPHEADER      => $curlHeaders,
            CURLOPT_POSTFIELDS      => $body,
            CURLOPT_TIMEOUT         => $this->serviceConfig['timeout'],
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_HEADER          => true,
            CURLOPT_NOBODY          => false,
            CURLOPT_TCP_NODELAY     => true,
        ]);

        $startTime  = microtime(true);
        $response   = curl_exec($ch);
        $duration   = microtime(true) - $startTime;
        $curlError  = curl_error($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // Note : curl_close() est un no-op depuis PHP 8.0 et déprécié en 8.5 → supprimé volontairement.

        // --- Timeout / erreur réseau ---
        if ($response === false) {
            $this->handleFailure($serviceKey, $curlError);

            return [
                'status' => 502,
                'body'   => json_encode([
                    'type'   => 'UPSTREAM_TIMEOUT',
                    'title'  => 'Service injoignable',
                    'status' => 502,
                    'detail' => "Le service {$this->serviceConfig['name']} n'a pas répondu dans le délai imparti ({$this->serviceConfig['timeout']}s). Cause : {$curlError}",
                ]),
                'headers' => ['Content-Type: application/json; charset=utf-8'],
            ];
        }

        // Extraire headers et body
        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody    = substr($response, $headerSize);

        // Logger la requête
        $this->logger->info("Proxy {$method} {$targetUrl}", [
            'status'   => $httpCode,
            'duration' => round($duration * 1000) . 'ms',
        ]);

        // Enregistrer le résultat dans le circuit breaker
        if ($httpCode >= 500) {
            $this->handleFailure($serviceKey, "HTTP {$httpCode}");
        } else {
            $this->handleSuccess($serviceKey);
        }

        // Formater les headers de réponse (filtrer les en-têtes de transfert)
        $responseHeaderLines = [];
        $skipHeaders = ['transfer-encoding', 'content-encoding'];
        foreach (explode("\r\n", $responseHeaders) as $line) {
            $headerName = strtolower(explode(':', $line, 2)[0] ?? '');
            if (!in_array($headerName, $skipHeaders) && $line !== '' && !str_starts_with($line, 'HTTP/')) {
                $responseHeaderLines[] = $line;
            }
        }

        return [
            'status'  => $httpCode,
            'body'    => $responseBody,
            'headers' => $responseHeaderLines,
        ];
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    private function handleSuccess(string $serviceKey): void
    {
        try {
            $this->circuitBreaker->recordSuccess($serviceKey);
        } catch (\Throwable $e) {
            // Silently ignore
        }
    }

    private function handleFailure(string $serviceKey, string $reason): void
    {
        try {
            $this->circuitBreaker->recordFailure($serviceKey);
            $this->logger->error("Circuit breaker failure for {$serviceKey}", ['reason' => $reason]);
        } catch (\Throwable $e) {
            // Silently ignore
        }
    }
}
