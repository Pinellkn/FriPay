<?php

/**
 * Circuit Breaker
 *
 * Supporte deux drivers de stockage :
 * - 'file'  : 1 fichier JSON par service (défaut, mono-instance)
 * - 'redis'  : Redis partagé (multi-instance, atomicité garantie)
 *
 * États :
 * - CLOSED    : fonctionnement normal
 * - OPEN      : le circuit est ouvert, les requêtes sont rejetées
 * - HALF_OPEN : période de test, on laisse passer un batch de requêtes
 */
class CircuitBreaker
{
    private string $storageDriver;
    private string $storagePath;
    private int $failureThreshold;
    private int $successThreshold;
    private int $openTimeoutSeconds;
    private ?\Redis $redis = null;

    public function __construct(array $config, array $storageConfig = [])
    {
        $this->storagePath       = rtrim($config['storage_path'], '/\\');
        $this->failureThreshold  = $config['failure_threshold'];
        $this->successThreshold  = $config['success_threshold'];
        $this->openTimeoutSeconds = $config['open_timeout_seconds'];

        $this->storageDriver = $storageConfig['driver'] ?? 'file';

        if ($this->storageDriver === 'redis') {
            $this->redis = $this->connectRedis($storageConfig['redis'] ?? []);
            if (!$this->redis) {
                $this->storageDriver = 'file';
            }
        }
    }

    /**
     * Vérifie si le circuit pour un service donné est fermé (autorise la requête).
     */
    public function isAvailable(string $serviceKey): bool
    {
        $state = $this->loadState($serviceKey);

        switch ($state['state']) {
            case 'closed':
                return true;

            case 'open':
                if (time() - $state['opened_at'] >= $this->openTimeoutSeconds) {
                    $state['state'] = 'half_open';
                    $state['successes'] = 0;
                    $state['half_open_requests'] = 0;
                    $this->saveState($serviceKey, $state);
                    return true;
                }
                return false;

            case 'half_open':
                $maxTestRequests = $this->successThreshold;
                $currentCount = $state['half_open_requests'] ?? 0;

                if ($currentCount >= $maxTestRequests) {
                    return false;
                }

                $state['half_open_requests'] = $currentCount + 1;
                $this->saveState($serviceKey, $state);
                return true;

            default:
                return true;
        }
    }

    /**
     * Enregistre un succès pour un service.
     */
    public function recordSuccess(string $serviceKey): void
    {
        $state = $this->loadState($serviceKey);

        switch ($state['state']) {
            case 'half_open':
                $state['successes']++;
                if ($state['successes'] >= $this->successThreshold) {
                    $state['state'] = 'closed';
                    $state['failures'] = 0;
                    $state['successes'] = 0;
                    $state['opened_at'] = null;
                }
                break;

            case 'closed':
                $state['failures'] = 0;
                break;

            default:
                break;
        }

        $this->saveState($serviceKey, $state);
    }

    /**
     * Enregistre un échec pour un service.
     */
    public function recordFailure(string $serviceKey): void
    {
        $state = $this->loadState($serviceKey);
        $state['failures']++;

        if ($state['state'] === 'half_open') {
            $state['state'] = 'open';
            $state['opened_at'] = time();
            $state['successes'] = 0;
        } elseif ($state['state'] === 'closed' && $state['failures'] >= $this->failureThreshold) {
            $state['state'] = 'open';
            $state['opened_at'] = time();
            $state['successes'] = 0;
        }

        $this->saveState($serviceKey, $state);
    }

    /**
     * Récupère l'état actuel du circuit (pour monitoring / debug).
     */
    public function getState(string $serviceKey): array
    {
        $state = $this->loadState($serviceKey);

        return [
            'service'         => $serviceKey,
            'state'           => $state['state'],
            'failures'        => $state['failures'],
            'remaining_retry' => $state['state'] === 'open'
                ? max(0, $this->openTimeoutSeconds - (time() - ($state['opened_at'] ?? time())))
                : 0,
        ];
    }

    // ── Stockage ─────────────────────────────────────────────────

    private function loadState(string $key): array
    {
        $default = [
            'state'     => 'closed',
            'failures'  => 0,
            'successes' => 0,
            'opened_at' => null,
        ];

        if ($this->storageDriver === 'redis' && $this->redis) {
            return $this->loadStateRedis($key, $default);
        }
        return $this->loadStateFile($key, $default);
    }

    private function saveState(string $key, array $state): void
    {
        if ($this->storageDriver === 'redis' && $this->redis) {
            $this->saveStateRedis($key, $state);
            return;
        }
        $this->saveStateFile($key, $state);
    }

    // ── Redis ────────────────────────────────────────────────────

    private function loadStateRedis(string $key, array $default): array
    {
        try {
            $raw = $this->redis->get("cb:{$key}");
            if (!$raw) {
                return $default;
            }
            $state = json_decode($raw, true);
            return is_array($state) ? array_merge($default, $state) : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    private function saveStateRedis(string $key, array $state): void
    {
        try {
            // TTL : open_timeout * 2 pour garder l'historique
            $ttl = $this->openTimeoutSeconds * 2;
            $this->redis->setex("cb:{$key}", $ttl, json_encode($state));
        } catch (\Throwable $e) {
            // Ignorer les erreurs Redis
        }
    }

    // ── Fichier ──────────────────────────────────────────────────

    private function loadStateFile(string $key, array $default): array
    {
        $path = $this->getFilePath($key);

        if (!file_exists($path)) {
            return $default;
        }

        $data = @file_get_contents($path);
        if ($data === false) {
            return $default;
        }

        $state = json_decode($data, true);
        return is_array($state) ? array_merge($default, $state) : $default;
    }

    private function saveStateFile(string $key, array $state): void
    {
        $path = $this->getFilePath($key);
        @file_put_contents($path, json_encode($state), LOCK_EX);
    }

    private function getFilePath(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key);
        return $this->storagePath . '/' . $safeKey . '.json';
    }

    // ── Connexion Redis ──────────────────────────────────────────

    private function connectRedis(array $config): ?\Redis
    {
        try {
            $redis = new \Redis();
            $redis->connect($config['host'] ?? '127.0.0.1', $config['port'] ?? 6379, 2.0);
            if (!empty($config['password'])) {
                $redis->auth($config['password']);
            }
            $redis->select($config['database'] ?? 0);
            return $redis;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getDriver(): string
    {
        return $this->storageDriver;
    }
}
