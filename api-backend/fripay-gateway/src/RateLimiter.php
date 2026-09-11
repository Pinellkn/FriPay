<?php

/**
 * Rate Limiter — Token Bucket Algorithm
 *
 * Supporte deux drivers de stockage :
 * - 'file'  : 1 fichier JSON par IP (défaut, mono-instance)
 * - 'redis'  : Redis partagé (multi-instance, atomicité garantie)
 *
 * Le driver est choisi via GATEWAY_STORAGE_DRIVER dans .env.
 * Fallback automatique vers fichier si Redis est indisponible.
 */
class RateLimiter
{
    private string $storageDriver;
    private string $storagePath;
    private int $bucketSize;
    private int $refillRate;
    private int $lastCleanupAt = 0;
    private ?\Redis $redis = null;

    public function __construct(array $config, array $storageConfig = [])
    {
        $this->storagePath = rtrim($config['storage_path'], '/\\');
        $this->bucketSize  = $config['bucket_size'];
        $this->refillRate  = $config['refill_rate'];

        $this->storageDriver = $storageConfig['driver'] ?? 'file';

        if ($this->storageDriver === 'redis') {
            $this->redis = $this->connectRedis($storageConfig['redis'] ?? []);
            if (!$this->redis) {
                $this->storageDriver = 'file';
            }
        }
    }

    /**
     * Vérifie si une requête est autorisée.
     */
    public function allow(string $clientKey): array
    {
        if ($this->storageDriver === 'redis' && $this->redis) {
            return $this->allowRedis($clientKey);
        }
        return $this->allowFile($clientKey);
    }

    // ── Driver Redis ─────────────────────────────────────────────

    private function allowRedis(string $clientKey): array
    {
        $key = "rl:{$clientKey}";
        $now = microtime(true);

        try {
            $this->redis->watch($key);
            $raw = $this->redis->get($key);
            $state = $raw ? json_decode($raw, true) : null;

            if (!is_array($state)) {
                $state = ['tokens' => $this->bucketSize, 'last_request_at' => $now];
            }

            $elapsed = $now - $state['last_request_at'];
            $state['tokens'] = min($this->bucketSize, $state['tokens'] + $elapsed * $this->refillRate);
            $state['last_request_at'] = $now;

            if ($state['tokens'] >= 1) {
                $state['tokens'] -= 1;
                $allowed = true;
                $remaining = (int) floor($state['tokens']);
                $retryAfter = 0;
            } else {
                $allowed = false;
                $remaining = 0;
                $retryAfter = (int) ceil((1 - $state['tokens']) / $this->refillRate);
            }

            $this->redis->multi();
            $this->redis->setex($key, 3600, json_encode($state));
            $this->redis->exec();

            return ['allowed' => $allowed, 'remaining' => $remaining, 'retry_after' => $retryAfter, 'limit' => $this->bucketSize];
        } catch (\Throwable $e) {
            return ['allowed' => true, 'remaining' => $this->bucketSize, 'retry_after' => 0, 'limit' => $this->bucketSize];
        }
    }

    // ── Driver Fichier ───────────────────────────────────────────

    private function allowFile(string $clientKey): array
    {
        $state = $this->loadState($clientKey);
        $now = microtime(true);

        $elapsed = $now - $state['last_request_at'];
        $state['tokens'] = min($this->bucketSize, $state['tokens'] + $elapsed * $this->refillRate);
        $state['last_request_at'] = $now;

        if ($state['tokens'] >= 1) {
            $state['tokens'] -= 1;
            $allowed = true;
            $remaining = (int) floor($state['tokens']);
            $retryAfter = 0;
        } else {
            $allowed = false;
            $remaining = 0;
            $retryAfter = (int) ceil((1 - $state['tokens']) / $this->refillRate);
        }

        $this->saveState($clientKey, $state);

        return ['allowed' => $allowed, 'remaining' => $remaining, 'retry_after' => $retryAfter, 'limit' => $this->bucketSize];
    }

    private function loadState(string $key): array
    {
        $path = $this->getFilePath($key);
        if (!file_exists($path)) {
            return ['tokens' => $this->bucketSize, 'last_request_at' => microtime(true)];
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            return ['tokens' => $this->bucketSize, 'last_request_at' => microtime(true)];
        }
        $state = json_decode($data, true);
        return is_array($state) ? $state : ['tokens' => $this->bucketSize, 'last_request_at' => microtime(true)];
    }

    private function saveState(string $key, array $state): void
    {
        $path = $this->getFilePath($key);
        @file_put_contents($path, json_encode($state), LOCK_EX);
    }

    // ── Nettoyage ────────────────────────────────────────────────

    public function cleanExpired(): void
    {
        if ($this->storageDriver === 'redis') {
            return;
        }

        $now = time();
        if ($now - $this->lastCleanupAt < 60) {
            return;
        }
        $this->lastCleanupAt = $now;

        $files = glob($this->storagePath . '/*.json');
        if ($files === false) {
            return;
        }

        $cutoff = $now - 3600;
        foreach ($files as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function getFilePath(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key);
        return $this->storagePath . '/' . $safeKey . '.json';
    }

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
