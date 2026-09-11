<?php

/**
 * Log Manager
 *
 * Logger structuré (JSON) vers fichier avec rotation automatique.
 */
class LogManager
{
    private string $logPath;
    private bool $enabled;
    private int $retentionDays;

    public function __construct(array $config)
    {
        $this->enabled        = $config['enabled'] ?? true;
        $this->logPath        = rtrim($config['log_path'] ?? __DIR__ . '/../storage/logs', '/\\');
        $this->retentionDays  = $config['log_rotate'] ?? 7;
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        if (!$this->enabled) {
            return;
        }

        $entry = json_encode([
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level'     => $level,
            'message'   => $message,
            'context'   => $context,
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'method'    => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'path'      => $_SERVER['REQUEST_URI'] ?? 'unknown',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $logFile = $this->logPath . '/gateway-' . gmdate('Y-m-d') . '.log';

        @file_put_contents($logFile, $entry . "\n", FILE_APPEND | LOCK_EX);

        // Rotation : supprimer les fichiers de plus de X jours
        static $rotationChecked = false;
        if (!$rotationChecked) {
            $rotationChecked = true;
            $this->rotate();
        }
    }

    private function rotate(): void
    {
        $files = glob($this->logPath . '/gateway-*.log');
        $cutoff = time() - ($this->retentionDays * 86400);

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
